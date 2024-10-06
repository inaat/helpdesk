<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Webklex\IMAP\Facades\Client;

class PipipingEmail extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:piping_email';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email to tickets';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle() {
        $client = Client::account('default');

        // Attempt to connect to the email server
        if (!$client->connect()) {
            Log::error("Failed to connect to the email server.");
            return 1; // Exit with an error code
        }

        while (true) {
            try {
                $folders = $client->getFolders();

                foreach ($folders as $folder) {
                    $allMessages = $folder->messages()->all()->whereUnseen()->get();

                    foreach ($allMessages as $message) {
                        $from = $message->getFrom();

                        if (empty($from)) {
                            Log::warning("No sender found for message ID: " . $message->getMessageId());
                            continue; // Skip to the next message
                        }

                        $fromData = $from[0];
                        if (!$fromData || !isset($fromData->mail)) {
                            Log::warning("From data is not valid for message ID: " . $message->getMessageId());
                            continue; // Skip to the next message
                        }

                        $user = User::where('email', $fromData->mail)->first();

                        // If the user does not exist, create a new user
                        if (empty($user)) {
                            $role = Role::where('slug', 'customer')->first();
                            $name = $this->split_name($fromData->personal);
                            $user = User::create([
                                'email' => $fromData->mail,
                                'password' => bcrypt('secret'), // Use bcrypt for password
                                'role_id' => $role->id ?? 5,
                                'first_name' => $name[0],
                                'last_name' => $name[1]
                            ]);
                        }

                        $subject = $message->getSubject();
                        $body = $message->getHTMLBody();
                        $messageIdObj = $message->getMessageId();
                        $messageId = $messageIdObj[0] ?? null;

                        if (!empty($messageId)) {
                            $ticket = Ticket::factory()->create([
                                'subject' => $subject,
                                'details' => $body,
                                'user_id' => $user->id,
                                'open' => now(),
                                'response' => null,
                                'due' => null,
                            ]);

                            $ticket->uid = app('App\HelpDesk')->getUniqueUid($ticket->id);
                            $ticket->save();

                            // Handle attachments
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

                            $message->setFlag('SEEN'); // Mark the message as seen
                        }
                    }
                }

                sleep(30); // Sleep for 30 seconds before checking again
            } catch (\Exception $e) {
                Log::error("An error occurred: " . $e->getMessage());
                sleep(10); // Sleep a bit before retrying
            }
        }

        return 0; // Normal exit code
    }

    private function split_name($name) {
        $name = trim($name);
        $last_name = (strpos($name, ' ') === false) ? '' : preg_replace('#.*\s([\w-]*)$#', '$1', $name);
        $first_name = trim(preg_replace('#' . preg_quote($last_name, '#') . '#', '', $name));
        return [$first_name, $last_name];
    }
}
