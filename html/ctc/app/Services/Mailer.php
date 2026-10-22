<?php


namespace App\Services;

use Phalcon\Logger\Logger as FileLogger;

abstract class Mailer extends Service
{

    /**
     * @var \Swift_Mailer
     */
    protected $manager;

    /**
     * @var array
     */
    protected $from;

    /**
     * @var FileLogger
     */
    protected $logger;

    public function __construct()
    {
        $options = $this->getMailerOptions();

        $this->manager = $this->createMailer($options);

        $this->from = $options['from'];

        $this->logger = $this->getLogger('mail');
    }

    public function send($email, $subject, $content, $attachment = null)
    {
        try {

            $message = new \Swift_Message();

            $message->setTo($email);
            $message->setFrom($this->from['email'], $this->from['name']);
            $message->setSubject($subject);
            $message->setBody($content, 'text/html');

            if ($attachment) {
                $message->attach(\Swift_Attachment::fromPath($attachment));
            }

            $count = $this->manager->send($message);

            $result = $count > 0;

        } catch (\Exception $e) {

            $this->logger->error('Send Mail Exception: ' . kg_json_encode([
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ]));

            $result = false;
        }

        return $result;
    }

    protected function formatSubject($subject)
    {
        $site = $this->getSettings('site');

        return sprintf('【%s】%s', $site['title'], $subject);
    }

    protected function formatContent($content)
    {
        return $content;
    }

    /**
     * 获取邮件配置
     *
     * @return array
     */
    protected function getMailerOptions()
    {
        $opt = $this->getSettings('mail');

        $options = [
            'host' => $opt['smtp_host'],
            'port' => $opt['smtp_port'],
            'from' => [
                'email' => $opt['smtp_from_email'],
                'name' => $opt['smtp_from_name'],
            ],
        ];

        if ($opt['smtp_encryption']) {
            $options['encryption'] = $opt['smtp_encryption'];
        }

        if ($opt['smtp_auth_enabled']) {
            $options['username'] = $opt['smtp_username'];
            $options['password'] = $opt['smtp_password'];
        }

        return $options;
    }

    /**
     * 创建 Mailer
     *
     * @param array $options
     * @return \Swift_Mailer
     */
    protected function createMailer(array $options)
    {
        $transport = new \Swift_SmtpTransport($options['host'], $options['port']);

        if (!empty($options['encryption'])) {
            $transport->setEncryption($options['encryption']);
        }

        if (!empty($options['username'])) {
            $transport->setUsername($options['username']);
            $transport->setPassword($options['password']);
        }

        return new \Swift_Mailer($transport);
    }

}
