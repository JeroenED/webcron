<?php


namespace App\Repository;


use App\Entity\Job;
use App\Entity\Run;
use App\Service\Secret;
use DateTime;
use Doctrine\ORM\EntityRepository;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;

/**
 *
 */
class JobRepository extends EntityRepository
{
    /**
     * @return array
     */
    public function getFailingJobs()
    {
        $runRepo = $this->getEntityManager()->getRepository(Run::class);
        /** @var Job[] $jobs */
        $jobs = $this->getAllJobs();

        $return = [];
        foreach($jobs as $job) {
            if($job->getData('needschecking')) {
                $return[] = $job;
            }
        }
        return $return;
    }

    /**
     * @return array
     */
    public function getRunningJobs(): array
    {
        $qb = $this->createQueryBuilder('job');
        return $qb
            ->where('job.running != 0')
            ->getQuery()->getResult();
    }

    /**
     * @param bool $idiskey
     * @return array
     */
    public function getAllJobs(bool $idiskey = false): array
    {
        $qb = $this->createQueryBuilder('job');

        $jobs = $qb->where('job.id = job.id');

        if($idiskey) {
            $jobs = $jobs->orderBy('job.id');
        } else {
            $jobs = $jobs
                ->orderBy('job.name')
                ->addOrderBy("JSON_VALUE(job.data, '$.host')")
                ->addOrderBy("JSON_VALUE(job.data, '$.service')");
        }

        /** @var Job $jobs */
        $jobs = $jobs->getQuery()->getResult();

        return $this->parseJobs($jobs);
    }

    /**
     * @param array $jobs
     * @return array
     */
    public function parseJobs(array $jobs): array
    {
        $runRepo = $this->getEntityManager()->getRepository(Run::class);

        foreach ($jobs as $key=>&$job) {
            $jobData = $job->getData();
            $job->setData('host-displayname', $jobData['host']);
            $job->setData('host', $jobData['host']);
            $job->setData('service', $jobData['service'] ?? '');
            $job->setData('norun', $job->getLastrun() !== null && $job->getNextrun() > $job->getLastrun());
            $job->setData('running', $job->getRunning() != 0);
            $failedruns = $runRepo->getRunsForJob($job->getId(), true, $jobData['fail-days']);
            $failed = count($failedruns);
            $all = count($runRepo->getRunsForJob($job->getId(), false, $jobData['fail-days']));
            $job->setData('lastfail', $failedruns[0] ?? NULL);
            $job->setData('needschecking', $all > 0  && (($failed / $all) * 100) > $jobData['fail-pct']);
            if(!empty($jobData['containertype']) && $jobData['containertype'] != 'none') {
                $job->setData('host-displayname', $jobData['service'] . ' on ' . $jobData['host']);
            }
        }

        return $jobs;
    }

    /**
     * @return array
     */
    public function getJobsDue(): array
    {
        $qb = $this->createQueryBuilder('job');
        return $qb
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->lte('job.nextrun', ':timestamp'),
                    $qb->expr()->orX(
                        $qb->expr()->isNull('job.lastrun'),
                        $qb->expr()->gt('job.lastrun', ':timestamp')
                    ),
                    $qb->expr()->in('job.running', [0,2])
                )
            )
            ->orWhere(
                $qb->expr()->andX(
                    $qb->expr()->notIn('job.running', [0,1,2]),
                    $qb->expr()->lt('job.running', ':timestamp')
                )
            )
            ->orWhere('job.running = 2')
            ->orderBy('job.running', 'DESC')
            ->addOrderBy('job.nextrun', 'ASC')
            ->setParameter(':timestamp', time())
            ->getQuery()->getResult();
    }

    /**
     * @param Job $job
     * @param bool $status
     * @return void
     */
    public function setJobRunning(Job $job, bool $status): void
    {
        $em = $this->getEntityManager();

        $job->setRunning($status ? 1 : 0);

        $em->persist($job);
        $em->flush();
    }

    /**
     * @param Job $job
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function setTempVar(Job &$job, string $name, mixed $value): void
    {
        $job->setData('temp_vars.' . $name, $value);
    }


    /**
     * @param Job $job
     * @param string|null $name
     * @return void
     */
    public function deleteTempVar(Job &$job, ?string $name = NULL ): void
    {
        $job->removeData('temp_vars' . ($name !== NULL ? '.' . $name : ''));
    }

    /**
     * @param Job $job
     * @param string $name
     * @param mixed|NULL $default
     * @return mixed
     */
    public function getTempVar(Job $job, string $name, mixed $default = NULL): mixed
    {
        return $job->getData('temp_vars' . $name) ?? $default;
    }

    /**
     * @param Job $job
     * @return array
     */
    private function runHttpJob(Job &$job): array
    {
        $client = new Client();

        if(!empty($job->getData('vars'))) {
            foreach($job->getData('vars') as $key => $var) {
                if (!empty($job->getData('basicauth-username'))) $job->setData('basicauth-username', str_replace('{' . $key . '}', $var['value'], $job->getData('basicauth-username')));
                $job->setData('url', str_replace('{' . $key . '}', $var['value'], $job->getData('url')));
            }
        }

        $url = $job->getData('url');
        $options['http_errors'] = false;
        $options['auth'] = !empty($job->getData('basicauth-username')) ? [$job->getData('basicauth-username'), $job->getData('basicauth-password')] : NULL;
        try {
            $res = $client->request('GET', $url, $options);
            $return['exitcode'] = $res->getStatusCode();
            $return['output'] = $res->getBody();
            $return['failed'] = !in_array($return['exitcode'], $job->getData('http-status'));
        } catch(GuzzleException $exception) {
            $return['exitcode'] = $exception->getCode();
            $return['output'] = $exception->getMessage();
            $return['failed'] = true;
        }

        return $return;
    }

    /**
     * @param Job $job
     * @return array
     */
    private function runCommandJob(Job &$job): array
    {
        if(!empty($job->getData('vars'))) {
            foreach ($job->getData('vars') as $key => $var) {
                $job->setData('command', str_replace('{' . $key . '}', $var['value'], $job->getData('command')));
            }
        }

        $command = $job->getData('command');
        if ($job->getData('containertype') == 'docker') {
            $command = $this->prepareDockerCommand($command, $job->getData('service'), $job->getData('container-user'));
        }
        try {
            if($job->getData('hosttype') == 'local') {
                $return = $this->runLocalCommand($command);
            } elseif($job->getData('hosttype') == 'ssh') {
                $return = $this->runSshCommand($command, $job->getData('host'), $job->getData('user'), $job->getData('ssh-privkey'), $job->getData('privkey-password'));
            }
            $return['failed'] = !in_array($return['exitcode'], $job->getData('response'));
        } catch (\RuntimeException $exception) {
            $return['exitcode'] = $exception->getCode();
            $return['output'] = $exception->getMessage();
            $return['failed'] = true;
        }

        return $return;
    }

    /**
     * @param string $command
     * @return array
     */
    private function runLocalCommand(string $command): array
    {
        if(function_exists('pcntl_signal')) pcntl_signal(SIGCHLD, SIG_DFL);
        $return['exitcode'] = NULL;
        $return['output'] = NULL;
        exec($command . ' 2>&1', $return['output'], $return['exitcode']);
        if(function_exists('pcntl_signal')) pcntl_signal(SIGCHLD, SIG_IGN);
        $return['output'] = implode("\n", $return['output']);
        return $return;
    }

    /**
     * @param string $command
     * @param string $host
     * @param string $user
     * @param string|null $privkey
     * @param string|null $password
     * @return array
     */
    private function runSshCommand(string $command, string $host, string $user, ?string $privkey, ?string $password): array
    {
        $ssh = new SSH2($host);
        $key = null;
        if(!empty($privkey)) {
            if(!empty($password)) {
                $key = PublicKeyLoader::load(base64_decode($privkey), $password);
            } else {
                $key = PublicKeyLoader::load(base64_decode($privkey));
            }
        } elseif (!empty($password)) {
            $key = $password;
        }
        if (!$ssh->login($user, $key)) {
            $return['output'] = "Login failed";
            $return['exitcode'] = 255;
            return $return;
        }
        $ssh->setTimeout(0);
        $return['output'] = $ssh->exec($command);
        $return['exitcode'] = $ssh->getExitStatus();
        $return['exitcode'] = (empty($return['exitcode'])) ? 0 : $return['exitcode'];
        return $return;
    }

    /**
     * @param Job $job
     * @param float $starttime
     * @param bool $manual
     * @return array|string[]
     * @throws \Doctrine\DBAL\Exception
     */
    private function runRebootJob(Job &$job, float &$starttime, bool &$manual): array
    {
        $em = $this->getEntityManager();
        if($job->getRunning() == 1) {
            $this->setTempVar($job, 'starttime', $starttime);
            $this->setTempVar($job, 'manual', $manual);
            $job->setData('reboot-command', str_replace('{reboot-delay}', $job['data']['reboot-delay'], $job->getData('reboot-command')));
            $job->setData('reboot-command', str_replace('{reboot-delay-secs}', $job['data']['reboot-delay-secs'], $job->getData('reboot-command')));

            if (!empty($job->getData('vars'))) {
                foreach ($job->getData('vars') as $key => $var) {
                    $job->setData('reboot-command', str_replace('{' . $key . '}', $var['value'], $job->getData('reboot-command')));
                }
            }

            $job->setRunning(time() + $job->getData('reboot-delay-secs') + ($job->getData('reboot-duration') * 60));
            $em->persist($job);
            $em->flush();

            try {
                if($job->getData('hosttype') == 'local') {
                    $this->runLocalCommand($job->getData('reboot-command'));
                } elseif($job->getData('hosttype') == 'ssh') {
                    $this->runSshCommand($job->getData('reboot-command'), $job->getData('host'), $job->getData('user'), $job->getData('ssh-privkey') ?? '', $job->getData('privkey-password') ?? '');
                }
            } catch (\RuntimeException $exception) {
                $return['exitcode'] = $exception->getCode();
                $return['output'] = $exception->getMessage();
                $return['failed'] = true;
                return $return;
            }
            return ['status' => 'deferred'];

        } elseif($job->getRunning() != 0) {
            if($job->getRunning() > time()) {
                return ['status' => 'deferred'];
            }
            $starttime = (float)$this->getTempVar($job, 'starttime');
            $this->deleteTempVar($job, 'starttime');
            $manual = $this->getTempVar($job, 'manual');
            $this->deleteTempVar($job, 'manual');

            $this->setJobRunning($job, true);

            if (!empty($job->getData('vars'))) {
                foreach ($job->getData('vars') as $key => $var) {
                    $job->setData('getservices-command', str_replace('{' . $key . '}', $var['value'], $job->getData('getservices-command')));
                }
            }
            try {
                if($job->getData('hosttype') == 'local') {
                    $return = $this->runLocalCommand($job->getData('getservices-command'));
                } elseif($job->getData('hosttype') == 'ssh') {
                    $return = $this->runSshCommand($job->getData('getservices-command'), $job->getData('host'), $job->getData('user'), $job->getData('ssh-privkey') ?? '', $job->getData('privkey-password') ?? '');
                }
            } catch (\RuntimeException $exception) {
                $return['exitcode'] = $exception->getCode();
                $return['output'] = $exception->getMessage();
                $return['failed'] = true;
                return $return;
            }
            $return['failed'] = !in_array($return['exitcode'], $job->getData('getservices-response'));
            return $return;
        }
        return ['success' => false, 'message' => 'You probably did something clearly wrong'];
    }

    /**
     * @param $job
     * @param $console
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function runNow(Job &$job, $console = false) {
        $em = $this->getEntityManager();
        $this->parseJob($job, true);
        $runRepo = $this->getEntityManager()->getRepository(Run::class);

        if($console == false && ($runRepo->isSlowJob($job)) || count($job->getRuns()) == 0 || $job->getData('crontype') === 'reboot') {
            if(in_array($job->getRunning(), [0,1,2])) {
                $job->setRunning(2);
                $em->persist($job);
                $em->flush();
            }
        } else {
            $output = $this->runJob($job, true);
            if(!(isset($output['status']) && $output['status'] == 'deferred'))
            return [
                'status' => 'ran',
                'output' => ($console) ? $output['output'] : htmlentities($output['output']),
                'exitcode' => $output['exitcode'],
                'runtime' => (float)$output['runtime'],
                'title' => !str_contains($output['flags'], RunRepository::FAILED) ? 'Cronjob successfully ran' : 'Cronjob failed. Please check output below',
                'success' => !str_contains($output['flags'], RunRepository::FAILED)
            ];
        }
        return ['success' => true, 'status' => 'deferred', 'title' => 'Cronjob has been scheduled', 'message' => 'Job was scheduled to be run. You will find the output soon in the job details'];
    }

    /**
     * @param string $command
     * @param string $service
     * @param string|null $user
     * @return string
     */
    private function prepareDockerCommand(string $command, string $service, ?string $user): string
    {
        $prepend = 'docker exec ';
        $prepend .= (!empty($user)) ? ' --user=' . $user . ' ' : '';
        $prepend .= $service . ' ';
        return $prepend . $command;
    }

    /**
     * @param int $job
     * @param bool $manual
     * @return array|string[]
     * @throws \Doctrine\DBAL\Exception
     */
    public function runJob(Job &$job, bool $manual): array
    {
        $em = $this->getEntityManager();
        $starttime = microtime(true);
        $this->parseJob($job, true);
        if ($job->getData('crontype') == 'http') {
            $result = $this->runHttpJob($job);
        } elseif ($job->getData('crontype') == 'command') {
            $result = $this->runCommandJob($job);
        } elseif ($job->getData('crontype') == 'reboot') {
            $result = $this->runRebootJob($job, $starttime, $manual);
            if(isset($result['status']) && $result['status'] == 'deferred') return $result;
        }
        $endtime = microtime(true);
        $runtime = $endtime - $starttime;

        // setting flags
        $flags = [];
        if ($result['failed'] === true) {
            $flags[] = RunRepository::FAILED;
        } else {
            $flags[] = RunRepository::SUCCESS;
        }

        if ($manual === true) {
            $flags[] = RunRepository::MANUAL;
        }

        // Remove secrets from output
        if(!empty($job->getData('vars'))) {
            foreach($job->getData('vars') as $key => $var) {
                if ($var['issecret']) {
                    $result['output'] = str_replace($var['value'], '{'.$key.'}', $result['output']);
                }
            }
        }

        // saving to database
        $em->getConnection()->close();
        $runRepo = $em->getRepository(Run::class);
        $runRepo->addRun($job->getId(), $result['exitcode'], floor($starttime), $runtime, $result['output'], $flags);
        if (!$manual){
            // setting nextrun to next run
            $nextrun = $job->getNextrun();
            do {
                $nextrun = $nextrun + $job->getInterval();
            } while ($nextrun < time());

            $job->setNextrun($nextrun);
            $this->deleteTempVar($job);
            $em->persist($job);
            $em->flush();
        }
        return  ['job_id' =>  $job->getId(), 'exitcode' => $result['exitcode'], 'timestamp' =>floor($starttime), 'runtime' => $runtime, 'output' => (string)$result['output'], 'flags' => implode("", $flags)];
    }

    /**
     * @param int $id
     * @return void
     * @throws \Doctrine\DBAL\Exception
     */
    public function unlockJob(?Job $job = NULL): void
    {
        $qb = $this->createQueryBuilder('job');
        $qry = $qb
            ->update()
            ->set('job.running', 0)
            ->where('job.running = 1');

        if($job !== NULL) {
            $qry = $qry
                ->andWhere('job = :job')
                ->setParameter(':job', $job);
        }
        $qry->getQuery()->execute();
    }

    /**
     * @param Job $job
     * @return bool
     */
    public function isLockedJob(Job $job): bool
    {
        return $job->getRunning() != 0;
    }

    /**
     * @param array $values
     * @return array
     */
    public function addJob(array $values)
    {
        $em = $this->getEntityManager();
        if(empty($values['crontype']) ||
            empty($values['name']) ||
            empty($values['interval']) ||
            empty($values['nextrun'])
        ) {
            throw new \InvalidArgumentException('Some fields are empty');
        }

        $job = $this->prepareJob($values);

        $em->persist($job);
        $em->flush();
        return ['success' => true, 'message' => 'Cronjob succesfully added'];
    }

    /**
     * @param int $id
     * @param array $values
     * @return array
     */
    public function editJob(int $id, array $values)
    {
        $em = $this->getEntityManager();

        if(empty($values['crontype']) ||
            empty($values['name']) ||
            empty($values['interval']) ||
            empty($values['nextrun'])
        ) {
            throw new \InvalidArgumentException('Some fields are empty');
        }
        $job = $this->find($id);
        $job = $this->prepareJob($values, $job);

        $em->persist($job);
        $em->flush();
        return ['success' => true, 'message' => 'Cronjob succesfully edited'];
    }

    /**
     * @param array $values
     * @param Job|null $job
     * @return Job
     */
    public function prepareJob(array $values, ?Job $job = NULL): Job
    {
        if ($job === NULL) {
            $job = new Job();
            $job->setRunning(0);
        }
        $job->setName($values['name']);
        $job->setInterval($values['interval']);

        if(empty($values['lastrun']) || (isset($values['lastrun-eternal']) && $values['lastrun-eternal'] == 'true')) {
            $job->setLastrun(NULL);
        } else {
            $job->setLastrun(DateTime::createFromFormat('d/m/Y H:i:s',$values['lastrun'])->getTimestamp());
        }

        $job->setNextrun(DateTime::createFromFormat('d/m/Y H:i:s', $values['nextrun'])->getTimestamp());
        $job->setData('retention', !empty($values['retention']) ? (int)$values['retention'] : NULL);

        $job->setData('crontype', $values['crontype'] ?? NULL);
        $job->setData('hosttype', $values['hosttype']);
        $job->setData('containertype', $values['containertype']);
        $job->setData('fail-pct', !empty($values['fail-pct']) ? (int)$values['fail-pct'] : 50);
        $job->setData('fail-days', !empty($values['fail-days']) ? (int)$values['fail-days'] : 7);

        if(!$job->hasData('crontype')) {
            throw new \InvalidArgumentException("Crontype cannot be empty");
        }
        switch($job->getData('crontype'))
        {
            case 'command':
                $job->setData('command', $values['command']);
                $job->setData('response', explode(',', $values['response']));
                break;
            case 'reboot':
                $job->setData('reboot-command', $values['reboot-command']);
                $job->setData('getservices-command', $values['getservices-command']);
                $job->setData('getservices-response', explode(',',$values['getservices-response']));
                $job->setData('reboot-duration', $values['reboot-duration']);
                if(!empty($values['reboot-delay']) || $values['reboot-delay'] == 0) {
                    $newsecretkey = count($values['var-value']);
                    $values['var-id'][$newsecretkey] = 'reboot-delay';
                    $values['var-issecret'][$newsecretkey] = false;
                    $values['var-value'][$newsecretkey] = (int)$values['reboot-delay'];

                    $newsecretkey = count($values['var-value']);
                    $values['var-id'][$newsecretkey] = 'reboot-delay-secs';
                    $values['var-issecret'][$newsecretkey] = false;
                    $values['var-value'][$newsecretkey] = (int)$values['reboot-delay'] * 60;
                }
                break;
            case 'http':
                $parsedUrl = parse_url($values['url']);
                $job->setData('url', $values['url']);
                $job->setData('http-status', explode(',', $values['http-status']));
                $job->setData('basicauth-username', $values['basicauth-username']);
                if(empty($parsedUrl['host'])) {
                    throw new \InvalidArgumentException('Some data was invalid');
                }
                if(!empty($values['basicauth-password'])) {
                    $newsecretkey = count($values['var-value']);
                    $values['var-id'][$newsecretkey] = 'basicauth-password';
                    $values['var-issecret'][$newsecretkey] = true;
                    $values['var-value'][$newsecretkey] = $values['basicauth-password'];
                }
                $job->setData('host', $parsedUrl['host']);
                break;
        }

        switch($job->getData('hosttype')) {
            default:
                if($job->getData('crotype') == 'http') break;
                $job->setData('hosttype', 'local');
            case 'local':
                $job->setData('host', 'localhost');
                break;
            case 'ssh':
                $job->setData('host', $values['host']);
                $job->setData('user', $values['user']);
                if(!empty($values['privkey-password'])) {
                    $newsecretkey = count($values['var-value']);
                    $values['var-id'][$newsecretkey] = 'privkey-password';
                    $values['var-issecret'][$newsecretkey] = true;
                    $values['var-value'][$newsecretkey] = $values['privkey-password'];
                }
                $privkeyid = NULL;
                if(!empty($_FILES['privkey']['tmp_name'])) {
                    $newsecretkey = count($values['var-value']);
                    $privkeyid = $newsecretkey;
                    $values['var-id'][$newsecretkey] = 'ssh-privkey';
                    $values['var-issecret'][$newsecretkey] = true;
                    $values['var-value'][$newsecretkey] = base64_encode(file_get_contents($_FILES['privkey']['tmp_name']));
                }
                if(isset($values['privkey-keep']) && $values['privkey-keep'] == true) {
                    $privkeyid = ($privkeyid === NULL) ? count($values['var-value']) : $privkeyid ;
                    $values['var-id'][$privkeyid] = 'ssh-privkey';
                    $values['var-issecret'][$privkeyid] = true;
                    $values['var-value'][$privkeyid] = $values['privkey-orig'];

                }
                break;
        }


        switch($job->getData('hosttype')) {
            default:
                if($job->getData('crontype') == 'http' || $job->getData('crontype') == 'reboot' ) break;
                $job->setData('containertype', 'none');
            case 'none':
                // No options for no container
                break;
            case 'docker':
                $job->setData('service', $values['service']);
                $job->setData('container-user', $values['container-user']);
                break;
        }

        if(!empty($values['var-value'])) {
            foreach($values['var-value'] as $key => $value) {
                if(!empty($value) || $value == 0) {
                    if(isset($values['var-issecret'][$key]) && $values['var-issecret'][$key] != false) {
                        $job->setData('vars.' . $values['var-id'][$key] . '.issecret', true);
                        $job->setData('vars.' . $values['var-id'][$key] . '.value', base64_encode(Secret::encrypt($values['var-value'][$key])));
                    } else {
                        $job->setData('vars.' . $values['var-id'][$key] . '.issecret', false);
                        $job->setData('vars.' . $values['var-id'][$key] . '.value', $values['var-value'][$key]);
                    }
                }
            }
        }
        return $job;
    }

    /**
     * @param int $id
     * @param bool $withSecrets
     * @return Job|mixed|object|null
     */
    public function parseJob(Job &$job, bool $withSecrets = false): void
    {
        if(!empty($job->getData('vars'))) {
            foreach ($job->getData('vars') as $key => &$value) {
                if ($value['issecret']) {
                    $job->setData('vars.' . $key . '.value', ($withSecrets) ? Secret::decrypt(base64_decode($value['value'])) : '');
                }
            }
        }

        switch($job->getData('crontype')) {
            case 'http':
                if($job->hasData('vars.basicauth-password.value')) {
                    $job->setData('basicauth-password', $job->getData('vars.basicauth-password.value'));
                    $job->removeData('vars.basicauth-password');
                }
                break;
            case 'reboot':
                $job->setData('reboot-delay', $job->getData('vars.reboot-delay.value'));
                $job->setData('reboot-delay-secs', $job->getData('vars.reboot-delay-secs.value'));

                $job->removeData('vars.reboot-delay');
                $job->removeData('vars.reboot-delay-secs');
                break;
        }

        switch($job->getData('hosttype')) {
            case 'ssh':
                if($job->hasData('vars.ssh-privkey.value')) {
                    $job->setData('ssh-privkey', $job->getData('vars.ssh-privkey.value'));
                    $job->removeData('vars.ssh-privkey');
                }
                if($job->hasData('vars.privkey-password.value')) {
                    $job->setData('privkey-password', $job->getData('vars.privkey-password.value'));
                    $job->removeData('vars.privkey-password');
                }
                break;
        }
        if($job->getData('crontype') == 'http') {
        }
    }

    /**
     * @param int $id
     * @return array
     */
    public function deleteJob(int $id)
    {
        $em = $this->getEntityManager();

        $job = $this->find($id);
        $em->remove($job);
        $em->flush();

        return ['success' => true, 'message' => 'Cronjob succesfully deleted'];
    }
}