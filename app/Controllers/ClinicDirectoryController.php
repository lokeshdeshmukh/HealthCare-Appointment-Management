<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\ClinicContext;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Clinic;
use App\Models\Doctor;

final class ClinicDirectoryController extends Controller
{
    public function home(Request $request): never
    {
        $currentClinic = ClinicContext::current();
        if ($currentClinic) {
            $this->renderClinicExperience($currentClinic, $currentClinic['name']);
        }

        $clinics = (new Clinic())->publicDirectory();
        $this->view('home', [
            'title' => config('app.name') . ' Appointment Booking',
            'clinics' => array_slice($clinics, 0, 6),
        ]);
    }

    public function index(Request $request): never
    {
        if (ClinicContext::isScoped()) {
            $this->redirect('/');
        }

        $this->view('clinics/index', [
            'title' => 'Find Clinics',
            'clinics' => (new Clinic())->publicDirectory(),
        ]);
    }

    public function showClinic(Request $request, $slug): never
    {
        $clinicModel = new Clinic();
        $clinic = $clinicModel->findBySlug((string) $slug);
        if (!$clinic || $clinic['status'] !== 'active') {
            $this->redirect('/clinics', 'Clinic not found.', 'error');
        }

        $scopedClinic = ClinicContext::current();
        if ($scopedClinic && (int) $scopedClinic['id'] !== (int) $clinic['id']) {
            $this->redirect('/', 'This clinic link is tied to a different clinic.', 'error');
        }

        $this->renderClinicExperience($clinic, $clinic['name']);
    }

    public function showDoctor(Request $request, $id): never
    {
        $doctor = (new Doctor())->publicFind((int) $id);
        if (!$doctor) {
            $this->redirect(ClinicContext::isScoped() ? '/' : '/clinics', 'Doctor not found.', 'error');
        }

        $scopedClinic = ClinicContext::current();
        if ($scopedClinic && (int) $doctor['clinic_id'] !== (int) $scopedClinic['id']) {
            $this->redirect('/', 'This doctor is not available from this clinic link.', 'error');
        }

        $this->view('clinics/doctor', [
            'title' => $doctor['name'],
            'doctor' => $doctor,
        ]);
    }

    private function renderClinicExperience(array $clinic, string $title): never
    {
        $doctors = $this->doctorCards((string) $clinic['slug']);
        if (count($doctors) === 1 && !empty($doctors[0]['id'])) {
            $this->redirect('/doctors/' . $doctors[0]['id'] . '/book');
        }

        $this->view('clinics/show', [
            'title' => $title,
            'clinic' => $clinic,
            'doctors' => $doctors,
        ]);
    }

    private function doctorCards(string $slug): array
    {
        return array_map(static function (array $doctor): array {
            $name = trim((string) ($doctor['name'] ?? ''));
            $bio = trim((string) ($doctor['bio'] ?? ''));

            return [
                'id' => (string) ($doctor['id'] ?? ''),
                'clinic_id' => (int) ($doctor['clinic_id'] ?? 0),
                'name' => $name,
                'specialization' => trim((string) ($doctor['specialization'] ?? '')),
                'profile_photo_path' => trim((string) ($doctor['profile_photo_path'] ?? '')),
                'initial' => $name !== '' ? strtoupper(substr($name, 0, 1)) : 'D',
                'bio_display' => $bio !== '' ? $bio : 'Appointments available for online booking.',
                'fee_display' => number_format((float) ($doctor['consultation_fee'] ?? 0), 2),
                'slot_duration_minutes' => (int) ($doctor['slot_duration_minutes'] ?? 0),
            ];
        }, (new Doctor())->forClinicSlug($slug));
    }
}
