<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Several Unsplash photo IDs used in the cars + excursions seed
     * migrations (2026_05_11_000300 / 2026_05_11_000400) return 404 in
     * production. Patch the affected `main_image` columns with verified
     * URLs so the public list cards stop showing broken image icons.
     *
     * Targeted by offer title (set in the seed migrations) — safe to
     * re-run; UPDATE is a no-op when the URL already matches.
     */
    public function up(): void
    {
        // --- cars: Toyota Hiace minivan (photo-1609712409631… → 404) ---
        $this->updateImage('car', 'Toyota Hiace 2023 · Minivan · Yerevan',
            'https://images.unsplash.com/photo-1469474968028-56623f02e42e?auto=format&fit=crop&w=1200&q=80'
        );

        // --- excursions: Garni + Geghard (photo-1601128814389… → 404) ---
        $this->updateImage('excursion', 'Garni Temple + Geghard Monastery · Half-day · Yerevan',
            'https://images.unsplash.com/photo-1444723121867-7a241cacace9?auto=format&fit=crop&w=1200&q=80'
        );

        // --- excursions: Lake Sevan (photo-1593194632060… → 404) ---
        $this->updateImage('excursion', 'Lake Sevan + Sevanavank · Full day · Yerevan',
            'https://images.unsplash.com/photo-1448375240586-882707db888b?auto=format&fit=crop&w=1200&q=80'
        );

        // --- excursions: Tatev cable car (photo-1571051568900… → 404) ---
        $this->updateImage('excursion', 'Tatev Monastery + "Wings of Tatev" cable car · Full day',
            'https://images.unsplash.com/photo-1495344517868-8ebaf0a2044a?auto=format&fit=crop&w=1200&q=80'
        );

        // --- excursions: Yerevan walk (photo-1605649461784… → 404) ---
        $this->updateImage('excursion', 'Yerevan city walking tour + Cascade + brandy factory',
            'https://images.unsplash.com/photo-1556761175-5973dc0f32e7?auto=format&fit=crop&w=1200&q=80'
        );
    }

    public function down(): void
    {
        // Intentionally a no-op — restoring known-broken URLs has no value.
    }

    private function updateImage(string $type, string $title, string $url): void
    {
        $offerId = (int) DB::table('offers')
            ->where('type', $type)
            ->where('title', $title)
            ->value('id');

        if ($offerId === 0) {
            return;
        }

        $table = match ($type) {
            'car' => 'cars',
            'excursion' => 'excursions',
            default => null,
        };

        if ($table === null) {
            return;
        }

        DB::table($table)->where('offer_id', $offerId)->update([
            'main_image' => $url,
            'updated_at' => now(),
        ]);
    }
};
