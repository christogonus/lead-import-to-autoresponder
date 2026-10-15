@php
    $entity = e(config('legal.entity'));
    $email = e(config('legal.support_email'));
    $retention = App\Models\Team::RETENTION_DAYS_AFTER_DELETION;
    $logDays = (int) config('logging.channels.daily.days');

    $sections = [
        [
            'id' => 'who',
            'title' => 'Who we are',
            'body' => '
                <p>'.$entity.' runs Lead2Crm and is the <strong>controller</strong> of the personal data about you, our customer, described in this policy. You can reach us about anything in it at <a href="mailto:'.$email.'">'.$email.'</a>.</p>
                <p>The contacts you upload to Lead2Crm are different: for those, you are the controller and we act as your <strong>processor</strong>. How we handle them is set out in our <a href="'.route('legal.dpa').'">Data Processing Agreement</a>, and summarised in section 4 below.</p>
            ',
        ],
        [
            'id' => 'what',
            'title' => 'What we collect about you',
            'body' => '
                <table>
                    <caption>Data we hold about our customers</caption>
                    <thead><tr><th scope="col">What</th><th scope="col">Why</th><th scope="col">Lawful basis</th></tr></thead>
                    <tbody>
                        <tr><td>Your name, email address and password (stored only as a one-way hash)</td><td>To create and secure your account and to contact you about it</td><td>Contract</td></tr>
                        <tr><td>Two-factor and passkey settings</td><td>To let you sign in more securely, if you turn them on</td><td>Contract</td></tr>
                        <tr><td>Teams, roles and invitations, including the email address of anyone you invite</td><td>To give the right people access to the right workspace</td><td>Contract; legitimate interests for invitees</td></tr>
                        <tr><td>Integration credentials (API keys and access tokens)</td><td>To send your lists where you ask. Stored encrypted and never shown back in full</td><td>Contract</td></tr>
                        <tr><td>Subscription status, plan, and a copy of Paddle&rsquo;s receipts and invoices. We never receive your card number</td><td>To know whether your account is paid for and to show your billing history</td><td>Contract; legal obligation</td></tr>
                        <tr><td>Your IP address and browser type, held with your sign-in session and in server logs</td><td>To keep you signed in, detect abuse and investigate faults</td><td>Legitimate interests (security)</td></tr>
                        <tr><td>Messages you send to support</td><td>To answer you</td><td>Legitimate interests</td></tr>
                    </tbody>
                </table>
                <p>We do not use analytics or advertising tools, we do not build profiles of you, and we make no automated decisions about you that have legal or similar effects.</p>
            ',
        ],
        [
            'id' => 'sharing',
            'title' => 'Who we share it with',
            'body' => '
                <p>We do not sell personal data, and we share it only with these kinds of service providers, each bound by contract to protect it and to use it only for us:</p>
                <ul>
                    <li><strong>Paddle.com</strong>, our reseller and merchant of record, which takes payment and handles tax. Paddle is an independent controller for the payment details you give it; see <a href="https://www.paddle.com/legal/privacy" rel="noopener">Paddle&rsquo;s privacy notice</a>.</li>
                    <li><strong>Our hosting provider</strong>, which runs the servers and database that store your account and lists.</li>
                    <li><strong>Our email delivery provider</strong>, which sends account emails such as password resets, verification links and team invitations.</li>
                    <li><strong>The platforms you connect.</strong> When you send a list, we pass the contacts on it to the integration you chose. That happens on your instruction, and from then on the platform&rsquo;s own terms apply.</li>
                </ul>
                <p>We will also disclose data if the law requires it, and only as far as it requires. A current list of the companies we use is available on request.</p>
            ',
        ],
        [
            'id' => 'your-contacts',
            'title' => 'The contacts you upload',
            'body' => '
                <p>The names, email addresses, phone numbers and countries on your lists are your data. We store them only so you can work with them and send them where you choose. We never email them, sell them, combine them with other data, or use them to train anything.</p>
                <p>If someone on one of your lists contacts us, we will pass their request to you, because you decide what happens to their data.</p>
            ',
        ],
        [
            'id' => 'retention',
            'title' => 'How long we keep it',
            'body' => '
                <ul>
                    <li><strong>Your account and lists</strong> stay until you delete them. A lapsed subscription does not delete anything, so you can come back.</li>
                    <li><strong>Deleting your account</strong> cancels your subscription at once and permanently erases your account and every team you own, with all its lists, contacts and integrations, straight away.</li>
                    <li><strong>A deleted team</strong> is kept for '.$retention.' days so a mistake can be undone, then erased with everything in it.</li>
                    <li><strong>Server logs</strong> are kept for '.$logDays.' days. They can include the email address of a contact that a platform rejected, which is why they are short-lived.</li>
                    <li><strong>Invoices</strong> are kept by Paddle for as long as tax law requires. Our own copy goes when your account does.</li>
                </ul>
            ',
        ],
        [
            'id' => 'rights',
            'title' => 'Your rights',
            'body' => '
                <p>Under the GDPR and the UK GDPR you can ask us to give you a copy of your data, correct it, erase it, restrict or object to how we use it, or move it to another service. Two of these you can do yourself, instantly, in <strong>Settings &rarr; Profile</strong>:</p>
                <ul>
                    <li><strong>Download my data</strong> gives you everything we hold about you, including all the lists in teams you own, as a JSON file.</li>
                    <li><strong>Delete account</strong> erases it all.</li>
                </ul>
                <p>For anything else, email <a href="mailto:'.$email.'">'.$email.'</a>. We reply within one month and do not charge for it. If you are unhappy with how we handle your data, you can complain to the data protection authority where you live or work; we would appreciate the chance to put it right first.</p>
            ',
        ],
        [
            'id' => 'security',
            'title' => 'How we protect it',
            'body' => '
                <ul>
                    <li>Every connection to Lead2Crm is encrypted with TLS.</li>
                    <li>Passwords are hashed; integration credentials are encrypted in the database.</li>
                    <li>Two-factor authentication and passkeys are available on every account.</li>
                    <li>Team data is separated, and every request is checked against your membership of the team it touches.</li>
                    <li>Access to production systems is limited to the people who need it to run the service.</li>
                </ul>
                <p>If a breach puts your data at risk, we will tell you and, where required, the regulator, without undue delay.</p>
            ',
        ],
        [
            'id' => 'transfers',
            'title' => 'International transfers',
            'body' => '
                <p>Some of our providers, including Paddle, may process data outside the UK and the European Economic Area. Where they do, we rely on an adequacy decision or on the European Commission&rsquo;s Standard Contractual Clauses (with the UK addendum) to keep your data protected to the same standard.</p>
            ',
        ],
        [
            'id' => 'cookies',
            'title' => 'Cookies',
            'body' => '
                <p>We use only the cookies needed to keep you signed in and to protect our forms. We use no tracking, analytics or advertising cookies. The full list is in our <a href="'.route('legal.cookies').'">Cookie Policy</a>.</p>
                <p>The pages you see before signing in load nothing from other companies: our fonts, images and code are all served from our own domain. The billing page loads Paddle&rsquo;s checkout script, because that is how you pay.</p>
            ',
        ],
        [
            'id' => 'children',
            'title' => 'Children',
            'body' => '
                <p>Lead2Crm is a business tool and is not intended for anyone under 18. We do not knowingly collect data about children, and our terms do not allow you to upload it.</p>
            ',
        ],
        [
            'id' => 'changes',
            'title' => 'Changes to this policy',
            'body' => '
                <p>When we change this policy we update the date at the top. If a change affects how we use your data in a meaningful way, we will email you before it takes effect.</p>
            ',
        ],
    ];
@endphp

<x-legal.document
    :title="__('Privacy Policy')"
    :summary="__('What we know about you, why, who else sees it, and how to get it back or have it erased. Short version: as little as possible, and you are in control.')"
    :sections="$sections"
/>
