<section aria-labelledby="cta-heading" class="px-5 pb-20 sm:px-8 sm:pb-28">
    <div class="relative mx-auto max-w-6xl overflow-hidden rounded-[2rem] bg-linear-to-br from-brand-600 via-brand-700 to-brand-900 px-6 py-16 text-center shadow-overlay sm:px-16">
        <div aria-hidden="true" class="absolute inset-0 bg-[radial-gradient(circle_at_1px_1px,rgb(255_255_255/0.14)_1px,transparent_0)] bg-[length:26px_26px]"></div>
        <div aria-hidden="true" class="absolute -top-24 -right-24 size-80 rounded-full bg-violet-400/30 blur-3xl"></div>

        <div class="relative">
            <h2 id="cta-heading" class="mx-auto max-w-2xl text-3xl font-bold tracking-tight text-balance text-white sm:text-4xl">
                {{ __('Your next list is already sitting in a spreadsheet') }}
            </h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-brand-100">
                {{ __('Setting up takes a few minutes. Your first send can go out today.') }}
            </p>
            <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-white px-6 py-3.5 font-semibold text-brand-800 shadow-raised hover:bg-brand-50">
                    {{ __('Get started') }}
                </a>
                <a href="{{ route('pricing') }}#faq" class="inline-flex items-center justify-center rounded-xl px-6 py-3.5 font-semibold text-white ring-1 ring-white/40 hover:bg-white/10">
                    {{ __('Read the FAQ') }}
                </a>
            </div>
        </div>
    </div>
</section>
