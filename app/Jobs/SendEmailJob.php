<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use App\Models\MailLog;

class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $params;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [5, 30];
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($params, $queue = 'send_email')
    {
        $this->onQueue($queue);
        $this->params = $params;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $originalMailConfig = config('mail');
        if (config('v2board.email_host')) {
            Config::set('mail.default', 'smtp');
            foreach (['host', 'port', 'encryption', 'username', 'password'] as $field) {
                Config::set('mail.mailers.smtp.' . $field, config('v2board.email_' . $field, config('mail.mailers.smtp.' . $field)));
            }
            Config::set('mail.mailers.smtp.scheme', config('v2board.email_encryption') === 'ssl' ? 'smtps' : 'smtp');
            Config::set('mail.from.address', config('v2board.email_from_address', config('mail.from.address')));
            Config::set('mail.from.name', config('v2board.app_name', 'V2Board'));
        }
        $params = $this->params;
        $email = $params['email'];
        $subject = $params['subject'];
        $params['template_name'] = 'mail.' . config('v2board.email_template', 'default') . '.' . $params['template_name'];
        try {
            app('mail.manager')->purge('smtp');
            Mail::send(
                $params['template_name'],
                $params['template_value'],
                function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                }
            );
        } catch (\Throwable $e) {
            MailLog::create([
                'email' => $params['email'],
                'subject' => $params['subject'],
                'template_name' => $params['template_name'],
                'error' => get_class($e) . ':' . $e->getCode()
            ]);
            // Let the queue retry transient SMTP failures. Swallowing the
            // exception previously marked failed deliveries as successful.
            throw $e;
        } finally {
            // Horizon reuses this process. Do not retain the previous job's
            // SMTP connection or credentials after either success or failure.
            Config::set('mail', $originalMailConfig);
            app('mail.manager')->purge('smtp');
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => NULL
        ];

        MailLog::create($log);
        return $log;
    }
}
