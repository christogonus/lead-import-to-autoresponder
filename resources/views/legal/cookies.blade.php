@php
    $session = e(config('session.cookie'));
    $lifetime = (int) config('session.lifetime');

    $sections = [
        [
            'id' => 'short-version',
            'title' => 'The short version',
            'body' => '
                <p>We do not track you. Lead2Crm sets no analytics, advertising, social media or &ldquo;personalisation&rdquo; cookies, and no third-party tracking pixels. The few cookies we do set are strictly necessary for the site to work, which is why there is no cookie banner: there is nothing to accept or decline.</p>
            ',
        ],
        [
            'id' => 'list',
            'title' => 'Every cookie we set',
            'body' => '
                <table>
                    <caption>Cookies set by Lead2Crm</caption>
                    <thead><tr><th scope="col">Name</th><th scope="col">What it does</th><th scope="col">How long</th></tr></thead>
                    <tbody>
                        <tr><td><code>'.$session.'</code></td><td>Keeps you signed in as you move between pages. Without it you would have to log in on every click.</td><td>'.$lifetime.' minutes of inactivity</td></tr>
                        <tr><td><code>XSRF-TOKEN</code></td><td>Proves a form was sent from our own pages, which stops other websites submitting forms in your name.</td><td>'.$lifetime.' minutes of inactivity</td></tr>
                        <tr><td><code>remember_web_&hellip;</code></td><td>Only if you tick &ldquo;Remember me&rdquo; when you sign in: keeps you signed in on that device.</td><td>Until you sign out</td></tr>
                    </tbody>
                </table>
                <p>None of these contain anything readable about you; they hold random identifiers that only mean something to our server.</p>
            ',
        ],
        [
            'id' => 'paddle',
            'title' => 'Paying through Paddle',
            'body' => '
                <p>When you open the Billing page in your account, we load Paddle&rsquo;s checkout so you can pay. Paddle may set its own strictly necessary cookies to process the payment and prevent fraud. Those are covered by <a href="https://www.paddle.com/legal/cookie-policy" rel="noopener">Paddle&rsquo;s cookie policy</a>. Paddle is not loaded anywhere else on our site.</p>
            ',
        ],
        [
            'id' => 'storage',
            'title' => 'Local storage',
            'body' => '
                <p>Inside your account, your browser&rsquo;s local storage remembers whether you prefer the light or dark theme. It never leaves your device.</p>
            ',
        ],
        [
            'id' => 'control',
            'title' => 'Controlling cookies',
            'body' => '
                <p>You can block or delete cookies in your browser settings at any time. If you block the ones above, you will be able to read our public pages but not sign in.</p>
            ',
        ],
    ];
@endphp

<x-legal.document
    :title="__('Cookie Policy')"
    :summary="__('No tracking cookies. No banner. Here is the complete list of what we do set, and why.')"
    :sections="$sections"
/>
