<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Clearance;
use App\Models\DocumentRequest;
use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Student;
use App\Models\SubjectOffering;
use App\Models\SystemSetting;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Safe to run on every deploy, only seeds an empty database
        if (User::count() > 0) {
            return;
        }

        $seed = json_decode(file_get_contents(database_path('seeders/data/seed.json')), true);

        // Plain passwords here, the User model hashes them automatically
        foreach ($seed['users'] as $row) {
            $row['username'] = strtolower($row['username']);
            User::create($row);
        }

        foreach ($seed['students'] as $row) Student::create($row);
        foreach ($seed['terms'] as $row) Term::create($row);
        foreach ($seed['offerings'] as $row) SubjectOffering::create($row);
        foreach ($seed['clearances'] as $row) Clearance::create($row);

        // Newest-first lists are ordered by seq DESC, so insert them in reverse
        // to keep the same order as the original mock data
        foreach (array_reverse($seed['enrollments']) as $row) Enrollment::create($row);
        foreach (array_reverse($seed['payments']) as $row) Payment::create($row);
        foreach (array_reverse($seed['grades']) as $row) Grade::create($row);
        foreach (array_reverse($seed['documents']) as $row) DocumentRequest::create($row);
        foreach (array_reverse($seed['notifications']) as $row) Notification::create($row);
        foreach (array_reverse($seed['audit']) as $row) AuditLog::create($row);

        SystemSetting::create(['data' => $seed['settings']]);
    }
}
