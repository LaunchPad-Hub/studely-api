@component('mail::message')
# Assessment Unlocked 🔓

Hello **{{ $student->user->name }}**,

Great news! The **{{ $assessment?->title ?? 'New Assessment' }}** has been assigned to your account and is now ready for you.

---

### 📋 Next Steps:
1.  **Log in** to your student dashboard.
2.  Navigate to the **"My Queue"** section.
3.  Select the assessment to view instructions and begin.

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Launch Student Dashboard
@endcomponent

<br>
<small>Please ensure you have a stable internet connection before starting.</small>

Good luck, you've got this!

Thanks,<br>
{{ config('app.name') }}
@endcomponent
