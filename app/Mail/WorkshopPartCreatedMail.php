<?php

namespace App\Mail;

use App\Models\Part;
use App\Models\PartImage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WorkshopPartCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Part $part)
    {
        $this->part->loadMissing(['images', 'storageLocation']);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Warsztat: %s - %s',
                $this->storageLocation(),
                $this->partNumber()
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.workshop-part-created',
            with: [
                'partNumber' => $this->partNumber(),
                'storageLocation' => $this->storageLocation(),
                'description' => $this->description(),
                'partId' => $this->part->id,
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $maxBytes = max(0, (int) config('services.workshop_intake.mail_attachments_max_mb', 20)) * 1024 * 1024;
        $totalBytes = 0;
        $attachments = [];

        foreach ($this->part->images as $image) {
            if (blank($image->path) || ! Storage::disk('public')->exists($image->path)) {
                Log::warning('Workshop intake notification image attachment is missing.', [
                    'part_id' => $this->part->id,
                    'part_image_id' => $image->id,
                    'path' => $image->path,
                ]);

                continue;
            }

            $path = Storage::disk('public')->path($image->path);
            $size = filesize($path) ?: 0;

            if ($maxBytes > 0 && $totalBytes + $size > $maxBytes) {
                Log::warning('Workshop intake notification image attachment skipped because size limit would be exceeded.', [
                    'part_id' => $this->part->id,
                    'part_image_id' => $image->id,
                    'path' => $image->path,
                    'max_bytes' => $maxBytes,
                    'current_total_bytes' => $totalBytes,
                    'attachment_bytes' => $size,
                ]);

                continue;
            }

            $totalBytes += $size;
            $attachments[] = Attachment::fromPath($path)->as($this->attachmentName($image));
        }

        return $attachments;
    }

    private function partNumber(): string
    {
        return (string) $this->part->part_number;
    }

    private function storageLocation(): string
    {
        return (string) ($this->part->storageLocation?->name ?? '');
    }

    private function description(): string
    {
        return (string) ($this->part->description ?? '');
    }

    private function attachmentName(PartImage $image): string
    {
        $extension = pathinfo($image->path, PATHINFO_EXTENSION) ?: 'jpg';
        $baseName = pathinfo($image->path, PATHINFO_FILENAME) ?: $this->part->id.'-'.$image->sort_order;

        return Str::slug($baseName) . '.' . strtolower($extension);
    }
}
