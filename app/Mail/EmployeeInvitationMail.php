<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Bucket D Phase D.1 — invitation email sent when a tenant manager adds an
 * employee with mode=invite. Recipient clicks the embedded link to land on
 * /accept-invitation/{token}, where they set their own password and activate
 * the account.
 */
class EmployeeInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Company $company,
        public Role $role,
        public UserInvitation $invitation,
        public ?User $invitedBy = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->company->name} on ZULU SPIN",
        );
    }

    public function content(): Content
    {
        // Invitation lands on the admin panel — that's where employees do
        // their work. app.admin_url falls back through frontend_url → app.url
        // (see config/app.php).
        $adminUrl = rtrim((string) config('app.admin_url', config('app.frontend_url', config('app.url'))), '/');

        return new Content(
            view: 'emails.employee-invitation',
            with: [
                'user' => $this->user,
                'company' => $this->company,
                'role' => $this->role,
                'invitation' => $this->invitation,
                'invitedBy' => $this->invitedBy,
                'acceptUrl' => "{$adminUrl}/accept-invitation/{$this->invitation->token}",
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
