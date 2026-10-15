@php
    $email = e(config('legal.support_email'));

    $sections = [
        [
            'id' => 'commitment',
            'title' => 'Our commitment',
            'body' => '
                <p>Everyone should be able to use Lead2Crm, whether they use a keyboard instead of a mouse, a screen reader, magnification, voice control, or simply prefer less motion on screen. We aim to meet the <a href="https://www.w3.org/TR/WCAG22/" rel="noopener">Web Content Accessibility Guidelines (WCAG) 2.2</a> at level AA, which is also the standard referred to by the Americans with Disabilities Act guidance, Section 508, EN 301 549 and the European Accessibility Act.</p>
            ',
        ],
        [
            'id' => 'what-we-do',
            'title' => 'What we have built in',
            'body' => '
                <ul>
                    <li><strong>Keyboard access.</strong> Everything can be reached and used with a keyboard, in a logical order, with a clearly visible focus outline.</li>
                    <li><strong>Skip links.</strong> The first thing on every page is a link straight to the main content.</li>
                    <li><strong>Structure.</strong> Pages use real headings in order, and landmarks for the header, navigation, main content and footer, so you can jump around with a screen reader.</li>
                    <li><strong>Names for everything.</strong> Every form field has a visible label, icon-only buttons have spoken names, and decorative images are hidden from assistive technology while meaningful ones have text alternatives.</li>
                    <li><strong>Contrast.</strong> Text meets a contrast ratio of at least 4.5:1 against its background, in both the light and dark themes.</li>
                    <li><strong>Not by colour alone.</strong> Statuses such as &ldquo;failed&rdquo; or &ldquo;active&rdquo; are written out, not just coloured.</li>
                    <li><strong>Tables.</strong> Data tables have headers and captions so they read correctly cell by cell.</li>
                    <li><strong>Reduced motion.</strong> If your device asks for reduced motion, we switch animations and smooth scrolling off.</li>
                    <li><strong>Zoom and reflow.</strong> Pages work at 200% zoom and on narrow screens without sideways scrolling.</li>
                    <li><strong>Dark mode.</strong> Inside your account you can choose light, dark or follow your system under Settings &rarr; Appearance.</li>
                </ul>
            ',
        ],
        [
            'id' => 'testing',
            'title' => 'How we check',
            'body' => '
                <p>We review pages against WCAG 2.2 AA using keyboard-only walkthroughs, screen reader testing, automated checks, and the Accessible Name &amp; Description Inspector (<a href="https://www.ssa.gov/accessibility/andi/help/howtouse.html" rel="noopener">ANDI</a>) from the U.S. Social Security Administration, which surfaces missing labels, heading problems, table structure and contrast failures. New features are checked the same way before they ship.</p>
            ',
        ],
        [
            'id' => 'limitations',
            'title' => 'Known limitations',
            'body' => '
                <ul>
                    <li><strong>Paddle checkout.</strong> Payment happens in a window provided by Paddle. Its accessibility is Paddle&rsquo;s responsibility; if you have trouble with it, email us and we will help you subscribe another way.</li>
                    <li><strong>Connecting integrations.</strong> Some platforms ask you to sign in on their own website to approve access. Those pages are outside our control.</li>
                    <li><strong>Sign-in timeout.</strong> For security, you are signed out after a period of inactivity. Nothing you have saved is lost, and a long import or send carries on while you are away.</li>
                </ul>
            ',
        ],
        [
            'id' => 'feedback',
            'title' => 'Tell us about a problem',
            'body' => '
                <p>If anything on Lead2Crm is hard to use with your setup, please email <a href="mailto:'.$email.'?subject=Accessibility">'.$email.'</a>. Tell us the page, what you were trying to do, and the browser and assistive technology you use if you are comfortable sharing it. We will reply within five working days and, while we fix it, help you get the task done another way.</p>
            ',
        ],
    ];
@endphp

<x-legal.document
    :title="__('Accessibility Statement')"
    :summary="__('Lead2Crm is built to be usable by everyone, including people who rely on a keyboard, screen reader or magnifier. Here is what we do, how we check it, and how to tell us when we miss.')"
    :sections="$sections"
/>
