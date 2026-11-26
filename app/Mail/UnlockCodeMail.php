<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UnlockCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $code, public string $purpose) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[게시판] 본인 확인 코드');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.unlock-code', with: [
            'ttl' => (int) config('security.unlock.code_ttl_minutes'),
            'what' => $this->purpose === 'posting' ? '글쓰기 제한 해제' : '계정 잠금 해제',
        ]);
    }
}
