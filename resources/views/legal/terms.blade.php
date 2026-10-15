@php
    $entity = e(config('legal.entity'));
    $email = e(config('legal.support_email'));
    $law = config('legal.governing_law')
        ? e(config('legal.governing_law'))
        : __('the country in which :entity is established', ['entity' => $entity]);
    $monthly = App\Enums\BillingPlan::Monthly;
    $yearly = App\Enums\BillingPlan::Yearly;

    $sections = [
        [
            'id' => 'agreement',
            'title' => 'The agreement',
            'body' => '
                <p>These terms are a contract between you and '.$entity.' (&ldquo;we&rdquo;, &ldquo;us&rdquo;) covering your use of Lead2Crm, the website and web application at this address. By creating an account you accept them, together with our <a href="'.route('legal.privacy').'">Privacy Policy</a>, our <a href="'.route('legal.dpa').'">Data Processing Agreement</a> and our <a href="'.route('legal.refunds').'">Refund Policy</a>, which form part of these terms.</p>
                <p>If you accept on behalf of a company or other organisation, you confirm you have authority to bind it, and &ldquo;you&rdquo; means that organisation.</p>
                <p>Lead2Crm is a tool for businesses. By subscribing you confirm you are using it for the purposes of your trade, business, craft or profession.</p>
            ',
        ],
        [
            'id' => 'service',
            'title' => 'What Lead2Crm does',
            'body' => '
                <p>Lead2Crm lets you upload or paste lists of contacts, check and organise them, and send them to email marketing, CRM and webinar platforms you connect (&ldquo;integrations&rdquo;). We keep your lists in your account so you can reuse them.</p>
                <p>Integrations are run by other companies under their own terms. We connect to them on your instruction, but we do not control them: if a platform changes or withdraws its interface, rejects a contact, or suspends your account with it, that is between you and that platform. We will make reasonable efforts to keep each integration working.</p>
                <p>We may improve, change or retire features. If we remove something material that you pay for, we will tell you in advance by email.</p>
            ',
        ],
        [
            'id' => 'account',
            'title' => 'Your account',
            'body' => '
                <ul>
                    <li>Give us accurate details and keep your email address current; it is how we reach you.</li>
                    <li>Keep your password private. We strongly recommend turning on two-factor authentication or a passkey in Settings.</li>
                    <li>You are responsible for what happens under your account, including what members of your teams do. Remove people who should no longer have access.</li>
                    <li>Tell us straight away at <a href="mailto:'.$email.'">'.$email.'</a> if you think someone has accessed your account without permission.</li>
                </ul>
            ',
        ],
        [
            'id' => 'billing',
            'title' => 'Plan, payment and renewal',
            'body' => '
                <p>Lead2Crm has one paid plan. There is no free plan and no free trial. You can pay $'.$monthly->monthlyRate().' a month, or $'.$yearly->amountPerCycle().' a year (the equivalent of $'.$yearly->monthlyRate().' a month). Both include unlimited leads and unlimited sends, and the same features.</p>
                <p>Payments are processed by <strong>Paddle.com</strong>, which acts as our reseller and merchant of record. You buy the subscription from Paddle, under <a href="https://www.paddle.com/legal/checkout-buyer-terms" rel="noopener">Paddle&rsquo;s buyer terms</a>, and Paddle handles card details, sales tax and VAT, and receipts. Prices on our site exclude any tax Paddle is required to add; the checkout shows the total before you pay.</p>
                <p>Your subscription renews automatically at the end of each month or year, and Paddle charges the payment method on file, until you cancel. If a renewal fails, Paddle retries over the following days and your account keeps working meanwhile; if it still cannot collect payment, the subscription ends.</p>
                <p>One subscription covers every team you own and everyone you invite to them.</p>
                <p>We may change the price. We will email you at least 30 days before a change affects you, and it applies from your next renewal after that notice. If you do not want to continue at the new price, cancel before then.</p>
            ',
        ],
        [
            'id' => 'cancellation',
            'title' => 'Cancellation and refunds',
            'body' => '
                <p>You can cancel at any time from the Billing page. Cancelling stops the next renewal; you keep full use of Lead2Crm until the end of the period you have paid for. You can also switch between monthly and yearly billing there, and Paddle prorates the difference.</p>
                <p><strong>Payments are not refunded</strong>, in whole or in part, including for unused time or if you stop using the service part way through a period. Running your account costs us money from the day you join. The details, including how this interacts with your statutory rights, are in our <a href="'.route('legal.refunds').'">Refund Policy</a>.</p>
                <p>When a subscription ends, your teams are paused, not deleted. Your lists stay in your account so you can resubscribe and carry on. To have everything erased, delete your account from Settings.</p>
            ',
        ],
        [
            'id' => 'your-data',
            'title' => 'Your data and the people on your lists',
            'body' => '
                <p>The lists you upload and everything in them remain yours. You give us permission to store, process and transmit them only to provide Lead2Crm to you, as described in our <a href="'.route('legal.dpa').'">Data Processing Agreement</a>. We do not sell, rent or share your contacts, and we never contact them ourselves.</p>
                <p>You are the controller of the personal data on your lists. That means you are responsible for:</p>
                <ul>
                    <li>having a lawful basis, and where the law requires it, valid consent, to hold each person&rsquo;s details and to send them marketing;</li>
                    <li>honouring unsubscribe and erasure requests (the do-not-contact list helps, but you have to use it);</li>
                    <li>following the laws that apply to your emails, such as the GDPR, the UK GDPR and PECR, the ePrivacy Directive, CAN-SPAM and CASL; and</li>
                    <li>following the terms of the platforms you send to.</li>
                </ul>
                <p>You can download any list, or a full export of your account, at any time.</p>
            ',
        ],
        [
            'id' => 'acceptable-use',
            'title' => 'Acceptable use',
            'body' => '
                <p>You must not use Lead2Crm to:</p>
                <ul>
                    <li>upload lists that were bought, rented, scraped or harvested, or any list whose members did not agree to hear from you;</li>
                    <li>send spam, phishing, malware, or anything unlawful, deceptive, hateful or harassing;</li>
                    <li>process special category data (for example health, religion or sexual orientation) or data about children;</li>
                    <li>get around a platform&rsquo;s own limits or anti-abuse measures, or someone else&rsquo;s do-not-contact request;</li>
                    <li>probe, overload or attack the service, scrape it, or try to reach data that is not yours; or</li>
                    <li>resell or sublicense access to Lead2Crm, except to clients you manage within your own teams.</li>
                </ul>
                <p>If we reasonably believe an account breaks these rules, we may suspend sends or the account while we look into it, and close it if the breach is serious or continues. Where we can, we will tell you first and give you a chance to put things right. Closure for a serious breach does not entitle you to a refund.</p>
            ',
        ],
        [
            'id' => 'availability',
            'title' => 'Availability and support',
            'body' => '
                <p>We work to keep Lead2Crm available and your data safe, but we do not promise uninterrupted service. Planned maintenance will be kept short and, where possible, announced in advance.</p>
                <p>Support is by email at <a href="mailto:'.$email.'">'.$email.'</a>. A person reads every message, and we aim to reply within two working days.</p>
            ',
        ],
        [
            'id' => 'ip',
            'title' => 'Our software',
            'body' => '
                <p>Lead2Crm, its code, design and name belong to us or our licensors. Your subscription gives you a personal, non-transferable right to use it during your subscription; it does not transfer ownership of anything. If you send us suggestions, we may use them without owing you anything.</p>
            ',
        ],
        [
            'id' => 'liability',
            'title' => 'Liability',
            'body' => '
                <p>Nothing in these terms limits liability for death or personal injury caused by negligence, for fraud, or for anything else that cannot lawfully be limited.</p>
                <p>Subject to that, Lead2Crm is provided &ldquo;as is&rdquo;. We are not liable for loss of profits, revenue, business, goodwill or data, or for indirect or consequential loss, or for the acts and omissions of integrations and other third parties. Our total liability to you in any twelve months is limited to the amount you paid us in those twelve months.</p>
                <p>You will compensate us for claims brought against us by third parties, including the people on your lists and regulators, that arise from your breach of these terms or of the law in how you use your lists.</p>
            ',
        ],
        [
            'id' => 'ending',
            'title' => 'Ending the agreement',
            'body' => '
                <p>You can end this agreement at any time by deleting your account in Settings. That cancels your subscription immediately and permanently erases your account and the teams you own, as described in our <a href="'.route('legal.privacy').'">Privacy Policy</a>.</p>
                <p>We can end it by giving you 30 days&rsquo; notice by email, in which case we will refund the unused part of any period you have paid for, or immediately under the acceptable use section above.</p>
            ',
        ],
        [
            'id' => 'general',
            'title' => 'Changes, law and the small print',
            'body' => '
                <p>We may update these terms. If a change matters, we will email you at least 30 days before it takes effect; carrying on using Lead2Crm after that means you accept it. The date at the top of this page shows when the terms last changed.</p>
                <p>These terms are governed by the law of '.$law.', and its courts have exclusive jurisdiction, except that if you are a consumer you keep the protection of the mandatory laws, and the right to bring proceedings in the courts, of the country where you live.</p>
                <p>If any part of these terms is found unenforceable, the rest still applies. If we do not enforce a right straight away, we have not given it up. You may not transfer this agreement without our consent; we may transfer it to a successor of our business, and will tell you if we do.</p>
            ',
        ],
    ];
@endphp

<x-legal.document
    :title="__('Terms of Service')"
    :summary="__('The agreement between you and :entity when you use Lead2Crm. Written to be read, not skimmed past.', ['entity' => config('legal.entity')])"
    :sections="$sections"
/>
