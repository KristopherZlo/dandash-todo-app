<?php

namespace App\Console\Commands;

use App\Models\RegistrationCode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GenerateRegistrationCodes extends Command
{
    protected $signature = 'registration-codes:generate
        {count=1 : Number of codes to generate}
        {--expires-hours=24 : Hours until expiration. Use 0 for no expiration}';

    protected $description = 'Generate one-time registration codes';

    public function handle(): int
    {
        $count = max(1, (int) $this->argument('count'));
        $expiresHours = (int) $this->option('expires-hours');
        $expiresAt = $expiresHours > 0 ? now()->addHours($expiresHours) : null;

        $createdCodes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateUniqueCode();

            RegistrationCode::create([
                'code' => $code,
                'expires_at' => $expiresAt,
            ]);

            $createdCodes[] = [
                'code' => $code,
                'expires_at' => $expiresAt?->toDateTimeString() ?? 'never',
            ];
        }

        $this->table(['Code', 'Expires At'], $createdCodes);
        $this->info(sprintf('Generated %d registration code(s).', $count));

        return self::SUCCESS;
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(10));
        } while (RegistrationCode::query()->where('code', $code)->exists());

        return $code;
    }
}
