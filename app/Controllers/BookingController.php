<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ClinicContext;
use App\Core\Controller;
use App\Core\Request;
use App\Models\Doctor;
use App\Services\AppointmentService;
use App\Services\SmsService;

final class BookingController extends Controller
{
    public function __construct(private readonly AppointmentService $appointments = new AppointmentService())
    {
    }

    public function showBooking(Request $request, $id): never
    {
        $doctor = (new Doctor())->publicFind((int) $id);
        if (!$doctor) {
            $this->redirect(ClinicContext::isScoped() ? '/' : '/clinics', 'Doctor not found.', 'error');
        }

        $scopedClinic = ClinicContext::current();
        if ($scopedClinic && (int) $doctor['clinic_id'] !== (int) $scopedClinic['id']) {
            $this->redirect('/', 'This doctor is not available from this clinic link.', 'error');
        }

        $this->view('patient/book', [
            'title' => 'Book Appointment',
            'doctor' => $doctor,
            'patientLoggedIn' => Auth::check('patient'),
            'redirectTo' => '/doctors/' . (int) $doctor['id'] . '/book',
            'smsConfigured' => (new SmsService())->isConfigured(),
        ]);
    }

    public function book(Request $request, $id): never
    {
        $doctorId = (int) $id;
        $patientId = (int) Auth::id();
        $doctor = (new Doctor())->publicFind($doctorId);

        if (!$doctor) {
            $this->redirect(ClinicContext::isScoped() ? '/' : '/clinics', 'Doctor not found.', 'error');
        }

        $scopedClinic = ClinicContext::current();
        if ($scopedClinic && (int) $doctor['clinic_id'] !== (int) $scopedClinic['id']) {
            $this->redirect('/', 'This doctor is not available from this clinic link.', 'error');
        }

        try {
            $this->appointments->create(
                $patientId,
                $doctorId,
                (string) $request->input('appointment_date'),
                (string) $request->input('start_time'),
                trim((string) $request->input('notes')) ?: null
            );
        } catch (\Throwable $exception) {
            $this->redirect('/doctors/' . $doctorId . '/book', $exception->getMessage(), 'error');
        }

        $this->redirect('/patient/dashboard', 'Appointment booked successfully.');
    }
}
