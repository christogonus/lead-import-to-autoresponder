@php
    $entity = e(config('legal.entity'));
    $email = e(config('legal.support_email'));
    $retention = App\Models\Team::RETENTION_DAYS_AFTER_DELETION;

    $sections = [
        [
            'id' => 'scope',
            'title' => 'What this agreement covers',
            'body' => '
                <p>This Data Processing Agreement (&ldquo;DPA&rdquo;) forms part of the <a href="'.route('legal.terms').'">Terms of Service</a> between you, the customer, and '.$entity.'. It applies whenever we process personal data on your behalf in providing Lead2Crm, and it is written to meet Article 28 of the GDPR and of the UK GDPR.</p>
                <p>For that data, you are the <strong>controller</strong> and we are your <strong>processor</strong>. If you are yourself a processor for a client, we are your sub-processor and you pass these commitments on.</p>
            ',
        ],
        [
            'id' => 'details',
            'title' => 'Details of the processing',
            'body' => '
                <table>
                    <caption>Description of processing</caption>
                    <tbody>
                        <tr><th scope="row">Subject matter</th><td>Storing, organising and transmitting contact lists you upload to Lead2Crm.</td></tr>
                        <tr><th scope="row">Duration</th><td>For as long as you keep the data in your account, ending at the latest when your account is deleted.</td></tr>
                        <tr><th scope="row">Nature and purpose</th><td>Import, validation, de-duplication, suppression, splitting and merging of lists, and sending contacts to the third-party platforms you choose.</td></tr>
                        <tr><th scope="row">Data subjects</th><td>Your leads, prospects, customers, subscribers, webinar registrants and other people whose details you upload.</td></tr>
                        <tr><th scope="row">Personal data</th><td>First and last name, email address, phone number and country, plus the reason for any do-not-contact entry.</td></tr>
                        <tr><th scope="row">Special categories</th><td>None. Our terms do not allow them to be uploaded.</td></tr>
                    </tbody>
                </table>
            ',
        ],
        [
            'id' => 'instructions',
            'title' => 'Processing only on your instructions',
            'body' => '
                <p>We process your data only to provide Lead2Crm as you use it, which is your documented instruction, and for no purpose of our own. Sending a list to an integration is your instruction to disclose it to that platform. If we believe an instruction breaks data protection law, we will tell you. If the law requires us to process data in some other way, we will tell you first unless the law forbids it.</p>
            ',
        ],
        [
            'id' => 'confidentiality',
            'title' => 'Confidentiality',
            'body' => '
                <p>Everyone we authorise to process your data is bound by a duty of confidentiality, and only people who need access to run or support the service have it.</p>
            ',
        ],
        [
            'id' => 'security',
            'title' => 'Security measures',
            'body' => '
                <ul>
                    <li>Encryption of all traffic in transit with TLS.</li>
                    <li>Encryption at rest of integration credentials; one-way hashing of passwords.</li>
                    <li>Logical separation of each team&rsquo;s data, with every request checked against team membership and role.</li>
                    <li>Optional two-factor authentication and passkeys for every user.</li>
                    <li>Server logs that may contain personal data are deleted automatically after a fixed period.</li>
                    <li>Restricted, individually attributable access to production systems.</li>
                </ul>
                <p>We review these measures as the service and the risks change, and will not reduce the overall level of protection.</p>
            ',
        ],
        [
            'id' => 'subprocessors',
            'title' => 'Sub-processors',
            'body' => '
                <p>You give general authorisation for us to use sub-processors for hosting, data storage and email delivery. We impose data protection obligations on each that are at least as protective as this DPA, and we remain responsible for them. We will give you at least 30 days&rsquo; notice by email before adding or replacing a sub-processor, so you can object; if we cannot reasonably accommodate the objection, you may end your subscription.</p>
                <p>The platforms you connect, such as Mailchimp or GoToWebinar, are not our sub-processors. You choose them, and you have your own agreement with each.</p>
                <p>The current list of sub-processors is available from <a href="mailto:'.$email.'">'.$email.'</a>.</p>
            ',
        ],
        [
            'id' => 'assistance',
            'title' => 'Helping you meet your obligations',
            'body' => '
                <p>Lead2Crm lets you find, correct, export, block and delete any contact yourself, which covers most requests from the people on your lists. Where you need more, we will help you respond to data subject requests, and with security, breach notification, impact assessments and consultation with regulators, as far as is reasonable given the nature of the processing. If a data subject contacts us directly, we will refer them to you.</p>
            ',
        ],
        [
            'id' => 'breaches',
            'title' => 'Personal data breaches',
            'body' => '
                <p>We will notify you without undue delay, and in any case within 48 hours, after becoming aware of a breach affecting your data. We will tell you what we know about its nature, the data and people affected, its likely consequences, and what we are doing about it, and keep you updated as we learn more.</p>
            ',
        ],
        [
            'id' => 'transfers',
            'title' => 'International transfers',
            'body' => '
                <p>Where a sub-processor processes your data outside the UK or the European Economic Area, we make sure the transfer is covered by an adequacy decision or by the European Commission&rsquo;s Standard Contractual Clauses, with the UK International Data Transfer Addendum where relevant.</p>
            ',
        ],
        [
            'id' => 'deletion',
            'title' => 'Deletion and return',
            'body' => '
                <p>You can export your data at any time. When you delete a list it is erased; when you delete a team it is erased after '.$retention.' days; when you delete your account it is erased immediately, along with every team you own. We do not keep copies unless the law requires it.</p>
            ',
        ],
        [
            'id' => 'audits',
            'title' => 'Audits',
            'body' => '
                <p>We will make available the information you reasonably need to show that we meet this DPA, and answer written security questionnaires. If that is not enough, or a regulator requires it, we will allow an audit by an independent auditor bound by confidentiality, on reasonable notice, during working hours, and no more than once a year. You bear its costs.</p>
            ',
        ],
        [
            'id' => 'precedence',
            'title' => 'Order of precedence',
            'body' => '
                <p>If this DPA conflicts with the Terms of Service on the processing of personal data, this DPA wins. If the Standard Contractual Clauses apply and conflict with either, the Clauses win.</p>
            ',
        ],
    ];
@endphp

<x-legal.document
    :title="__('Data Processing Agreement')"
    :summary="__('Our commitments as the processor of the contact lists you upload, as required by Article 28 of the GDPR. It is part of the terms you accept when you sign up, so there is nothing to sign.')"
    :sections="$sections"
/>
