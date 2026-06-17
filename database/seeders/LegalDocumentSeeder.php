<?php

namespace Database\Seeders;

use App\Models\LegalDocument;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

class LegalDocumentSeeder extends Seeder
{
    /**
     * Seeds the platform-level (clinic_id = null) legal documents listed in config/legal.php,
     * reading each document's text from resources/legal/<type>/<version>.md. Idempotent:
     * firstOrCreate keyed on (clinic_id null, type, is_active) so re-running never duplicates
     * an active doc. Publishing a new version = a new config version + a new .md file.
     */
    public function run(): void
    {
        $createdBy = $this->resolveSuperadmin();

        if ($createdBy === null) {
            return;
        }

        /** @var array<string, array{version: string, title: string}> $documents */
        $documents = config('legal.documents', []);

        foreach ($documents as $type => $meta) {
            LegalDocument::withoutGlobalScopes()->firstOrCreate(
                [
                    'clinic_id' => null,
                    'type' => $type,
                    'is_active' => true,
                ],
                [
                    'version' => $meta['version'],
                    'title' => $meta['title'],
                    'content' => $this->readContent($type, $meta['version']),
                    'effective_date' => now()->toDateString(),
                    'created_by' => $createdBy,
                ],
            );
        }
    }

    private function readContent(string $type, string $version): string
    {
        $path = resource_path("legal/{$type}/{$version}.md");

        if (! is_file($path)) {
            throw new RuntimeException("Legal document text not found: {$path}");
        }

        return trim((string) file_get_contents($path));
    }

    private function resolveSuperadmin(): ?int
    {
        $role = Role::where('name', 'superadmin')->whereNull('clinic_id')->first();

        if ($role === null) {
            return null;
        }

        // Query the pivot directly. This seeder runs after DemoSeeder, which leaves the Spatie
        // team context set to a clinic — so the team-scoped roles relation would hide the global
        // (clinic_id null) superadmin assignment. A direct lookup ignores team scope.
        return DB::table('model_has_roles')
            ->where('role_id', $role->id)
            ->where('model_type', (new User)->getMorphClass())
            ->value('model_id');
    }
}
