<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client;

class MarsEmailPiping extends Command {
    protected $signature = 'command:mars_piping_email';
    protected $description = 'Process emails from inbox to create tickets, including CC information';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle() {
        $client = Client::account('mars_email');

        if (!$client->connect()) {
            Log::error("Failed to connect to the email server.");
            return 1;
        }

        while (true) {
            try {
                $inbox = $client->getFolder('INBOX');
                $messages =  $inbox->messages()->unseen()->get();

                foreach ($messages as $message) {
                    $from = $message->getFrom();
                    if (empty($from)) {
                        Log::warning("No sender found for message ID: " . $message->getMessageId());
                        continue;
                    }

                    $fromData = $from[0];
                    if (!$fromData || !isset($fromData->mail)) {
                        Log::warning("From data is not valid for message ID: " . $message->getMessageId());
                        continue;
                    }
              
                    $user = $this->getOrCreateUser($fromData);

                    $subject = $message->getSubject();
                    $body = $message->getHTMLBody();
                    $plainBody = $message->getTextBody(); // Get plain text body
                    $messageId = $message->getMessageId()[0] ?? null;

                    if (!empty($messageId)) {
                        $cc = $message->getCc();
                        $assigned_to = null;
                        
                        if (!empty($cc) && isset($cc[0]->mail)) {
                            $assignedUser = User::where('email', $cc[0]->mail)->first();
                            $assigned_to = $assignedUser ? $assignedUser->id : null;
                        }
                        $ticket = $this->createTicket($user, $subject, $body, $plainBody , $assigned_to);
                        $this->processAttachments($message, $ticket, $user);
                        $message->setFlag('SEEN');
                    }
                }

            } catch (\Exception $e) {
                Log::error("An error occurred: " . $e->getMessage());
                sleep(10);
            }
        }

        return 0;
    }

    private function getOrCreateUser($fromData) {
        $user = User::where('email', $fromData->mail)->first();

        if (empty($user)) {
            $role = Role::where('slug', 'customer')->first();
            $name = $this->split_name($fromData->personal);
            $user = User::create([
                'email' => $fromData->mail,
                'password' => bcrypt('secret'),
                'role_id' => $role->id ?? 5,
                'first_name' => $name[0],
                'last_name' => $name[1]
            ]);
        }

        return $user;
    }

    private function createTicket($user, $subject, $body, $plainBody ,$assigned_to) {
        $combinedBody = $body . "\n\n" . strip_tags($plainBody); // Use strip_tags to remove HTML from plain body
        $ticket = Ticket::factory()->create([
            'subject' => $subject,
            'details' =>     $combinedBody ,
            'user_id' => $user->id,
            'open' => now(),
            'response' => null,
            'due' => null,
            'assigned_to'=>$assigned_to
        ]);

        $ticket->uid = app('App\HelpDesk')->getUniqueUid($ticket->id);
        $ticket->save();

        return $ticket;
    }

    private function processAttachments($message, $ticket, $user) {
        $message->getAttachments()->each(function ($attachment) use ($message, $ticket, $user) {
            $origin_name = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $message->getMessageId() . '_' . $attachment->name);
            $directory = public_path('files/tickets/');

            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            $public_path = $directory . $origin_name;

            if (file_put_contents($public_path, $attachment->content) !== false) {
                $file_path = 'tickets/' . $origin_name;
                Attachment::create([
                    'ticket_id' => $ticket->id,
                    'name' => $attachment->name,
                    'size' => $attachment->size,
                    'path' => $file_path,
                    'user_id' => $user->id
                ]);
            } else {
                Log::error("Failed to save attachment for ticket ID " . $ticket->id);
            }
        });
    }

    private function split_name($name) {
        $name = trim($name);
        $last_name = (strpos($name, ' ') === false) ? '' : preg_replace('#.*\s([\w-]*)$#', '$1', $name);
        $first_name = trim(preg_replace('#' . preg_quote($last_name, '#') . '#', '', $name));
        return [$first_name, $last_name];
    }

}