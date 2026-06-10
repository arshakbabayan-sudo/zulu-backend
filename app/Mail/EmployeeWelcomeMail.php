<?php

namespace App\Mail;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * §7 (2026-06-10) — sent when a manager creates an employee with mode=direct
 * (manager sets the password and hands it over personally). The mail tells the
 * employee their account exists and where to sign in; it deliberately does NOT
 * contain the password.
 */
class EmployeeWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Company $company,
        public Role $role,
        public ?User $createdBy = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your {$this->company->name} account on ZULU SPIN is ready",
        );
    }

    public function content(): Content
    {
        $adminUrl = rtrim((string) config('app.admin_url', config('app.frontend_url', config('app.url'))), '/');

        return new Content(
            view: 'emails.employee-welcome',
            with: [
                'user' => $this->user,
                'company' => $this->company,
                'role' => $this->role,
                'createdBy' => $this->createdBy,
                'loginUrl' => "{$adminUrl}/login",
            ],
        );
    }
}
