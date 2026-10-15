@php
    $email = e(config('legal.support_email'));

    $sections = [
        [
            'id' => 'policy',
            'title' => 'Our policy',
            'body' => '
                <p><strong>Lead2Crm does not offer refunds.</strong> This applies to monthly and yearly payments, to renewals, and to unused time when you cancel or stop using the service part way through a period.</p>
                <p>The reason is simple: from the moment you join, your account costs us money to run. Your lists are stored, imports are processed, and every send uses servers and queues that we pay for, whether you use them once or every day.</p>
            ',
        ],
        [
            'id' => 'before-you-pay',
            'title' => 'Deciding before you pay',
            'body' => '
                <p>Because there is no trial, we have tried to put everything you need to decide on the <a href="'.route('home').'">home page</a>, the <a href="'.route('marketing.integrations').'">integrations page</a> and the <a href="'.route('pricing').'#faq">FAQ</a>. If you are not sure Lead2Crm will work with your platform or your files, email us at <a href="mailto:'.$email.'">'.$email.'</a> before you subscribe and we will tell you honestly.</p>
                <p>If you want the smallest commitment, choose monthly billing.</p>
            ',
        ],
        [
            'id' => 'cancelling',
            'title' => 'Cancelling',
            'body' => '
                <p>Cancel at any time from the Billing page in your account. You will not be charged again, and your account keeps working in full until the end of the period you have already paid for. Nothing is deleted when a subscription ends, so you can come back to your lists later.</p>
            ',
        ],
        [
            'id' => 'exceptions',
            'title' => 'The exceptions',
            'body' => '
                <ul>
                    <li><strong>Charged in error.</strong> If you were billed twice, or after you had cancelled, tell us and we will refund the mistaken charge in full.</li>
                    <li><strong>We close the service or your account without cause.</strong> If we end the agreement on notice, as our terms allow, we refund the unused part of your current period.</li>
                    <li><strong>Your statutory rights.</strong> Lead2Crm is sold to businesses. If you are nonetheless a consumer, and the law where you live gives you a right to cancel and be refunded that cannot be waived, that right still applies. For consumers in the EU and UK, the 14-day right of withdrawal from a contract for digital services ends once the service has started with your agreement. By subscribing you ask us to start straight away, and you acknowledge that you lose that right once it has.</li>
                </ul>
            ',
        ],
        [
            'id' => 'paddle',
            'title' => 'Paddle, our reseller',
            'body' => '
                <p>Payments are made to Paddle.com, which sells Lead2Crm on our behalf as merchant of record. Any refund in the cases above is issued by Paddle to your original payment method. Please contact us first at <a href="mailto:'.$email.'">'.$email.'</a>; we will arrange it with Paddle.</p>
            ',
        ],
    ];
@endphp

<x-legal.document
    :title="__('Refund Policy')"
    :summary="__('We do not offer refunds, because the service costs money to run from the first day. Here is what that means, and the few exceptions.')"
    :sections="$sections"
/>
