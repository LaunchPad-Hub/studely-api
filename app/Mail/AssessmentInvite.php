<?php

namespace App\Mail;

use App\Models\Assessment;
use App\Models\Student;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AssessmentInvite extends Mailable
{
    use Queueable, SerializesModels;

    public Assessment $assessment;

    public Student $student;

    public string $url;

    /**
     * Create a new message instance.
     */
    public function __construct(Assessment $assessment, Student $student)
    {
        $this->assessment = $assessment;
        $this->student = $student;
        $this->url = config('app.frontend_url', 'http://localhost:3000') . '/dashboard';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Congratulations! You're qualified for the {$this->assessment?->title}",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'emails.final_assessment',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
