{{--
    Public partner page (REF 1CF-PARTNER-PAGE-001). This view holds NO wording of its own beyond section headings:
    role cards come from the module registry and every other text block and list from the settings store
    (App\Support\PartnerPage\PartnerPageData). A block whose value is empty is not rendered. Layout follows the owner's
    design (navy hero, cream sections, navy benefit band and form, one yellow accent). Styles are scoped under .pp and
    live in this view so the page never depends on which Tailwind classes a build happened to include.
--}}
@php
    $tones = ['blue' => '#4DA3FF', 'green' => '#2DD4A0', 'amber' => '#FFC21A', 'rose' => '#FF6FA3', 'violet' => '#9B7BFF', 'teal' => '#2DD4C4'];
    $shapes = ['border-radius:16px', 'border-radius:50%', 'border-radius:16px 4px 16px 4px', 'border-radius:4px 16px 4px 16px', 'border-radius:50% 16px 50% 16px', 'border-radius:10px'];
    $firstTab = array_key_first($faqTabs);
    $firstQuestion = $firstTab ? '0' : '';
    $check = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>';
    $arrow = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    $lineCount = count($heroLines);
@endphp

<x-layouts.customer :title="$seoTitle" :indexable="true" :metaDescription="$seoDescription">
<style>
.pp{--navy:#0B1220;--navy-2:#131C2E;--line:#26324C;--line-2:#4A5670;--cream:#F5F3EE;--white:#fff;--ink:#12161F;--muted:#3A4150;--soft:#4A5260;--sky:#B4BDCB;--mist:#E4E9F2;--sand:#E3DFD5;--edge:#B9B4A6;--accent:#FFC21A;--bad:#B3261E;
 background:var(--cream);color:var(--ink);font-size:16px;line-height:1.5}
.pp *{box-sizing:border-box}
.pp [x-cloak]{display:none!important}
html:has(.pp){scroll-behavior:smooth}
.pp h1,.pp h2,.pp h3,.pp p,.pp ul,.pp ol{margin:0}
.pp h1,.pp h2,.pp h3{text-wrap:balance}
.pp a{color:inherit}
.pp a:focus-visible,.pp button:focus-visible,.pp input:focus-visible,.pp select:focus-visible{outline:3px solid var(--accent);outline-offset:2px;box-shadow:0 0 0 2px var(--navy)}
.pp .wrap{max-width:1200px;margin:0 auto;padding-inline:24px}
.pp .sec{padding-block:88px;scroll-margin-top:16px}
.pp .h2{font-size:clamp(34px,4.6vw,54px);line-height:1.05;font-weight:800;letter-spacing:-.02em}
.pp .lead{margin-top:18px;font-size:19px;line-height:1.55;max-width:680px}
.pp .btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:44px;padding:0 22px;border-radius:999px;font-weight:700;font-size:15px;text-decoration:none;border:1.5px solid transparent;cursor:pointer;font-family:inherit}
.pp .btn-accent{background:var(--accent);color:var(--navy)}
.pp .btn-line{border-color:var(--line-2);color:#fff;background:transparent}
.pp .btn-out{background:#fff;color:var(--ink);border-color:var(--edge)}
.pp .btn-lg{min-height:56px;padding:0 30px;font-size:18px}
.pp .chk{display:flex;color:var(--accent)}
.pp .hero{background:var(--navy);color:#fff;overflow:hidden}
.pp .top{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;padding-block:22px}
.pp .logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:#fff}
.pp .logo i{display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:11px;background:var(--accent);color:var(--navy);font-weight:800;font-size:22px;font-style:normal}
.pp .logo b{font-size:22px}.pp .logo span{font-weight:500;font-size:15px;color:var(--sky)}
.pp .nav{display:flex;flex-wrap:wrap;gap:6px 26px}
.pp .nav a{color:var(--mist);text-decoration:none;font-weight:500;font-size:15px;padding:10px 0}
.pp .hero-grid{display:flex;flex-wrap:wrap;align-items:center;gap:56px 64px;padding-block:48px 72px}
.pp .hero-l{flex:1 1 520px;min-width:0}
.pp .badge{display:inline-flex;align-items:center;gap:10px;padding:8px 16px;border-radius:999px;border:1.5px solid #34425E;font-size:14px;font-weight:500;color:var(--mist)}
.pp .badge i{width:9px;height:9px;border-radius:50%;background:var(--accent)}
.pp .hero h1{margin-top:26px;font-size:clamp(42px,6.4vw,80px);line-height:1;font-weight:800;letter-spacing:-.02em}
.pp .hero h1 em{font-style:normal;color:var(--accent)}
.pp .hero .sub{margin-top:26px;max-width:560px;font-size:20px;line-height:1.5;color:var(--sky)}
.pp .ctas{display:flex;flex-wrap:wrap;gap:14px;margin-top:34px}
.pp .ticks{display:flex;flex-direction:column;gap:12px;margin-top:38px;color:var(--mist);list-style:none;padding:0}
.pp .ticks li{display:flex;align-items:center;gap:12px}
.pp .claim{margin-top:18px;font-weight:700;color:var(--accent)}
.pp .phone{flex:0 1 400px;min-width:min(100%,300px);margin-inline:auto;background:var(--navy-2);border:1.5px solid var(--line);border-radius:32px;padding:22px;display:flex;flex-direction:column;gap:16px}
.pp .phone .row{display:flex;align-items:center;justify-content:space-between;gap:10px}
.pp .online{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:var(--accent);color:var(--navy);font-weight:700;font-size:13px}
.pp .online i{width:8px;height:8px;border-radius:50%;background:var(--navy)}
.pp .offer{background:#fff;color:var(--ink);border-radius:22px;padding:20px;display:flex;flex-direction:column;gap:12px}
.pp .offer small{font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--soft)}
.pp .offer h3{font-size:26px;line-height:1.1}
.pp .offer .meta{display:flex;flex-direction:column;gap:6px;font-size:15px;color:var(--muted)}
.pp .offer .acts{display:flex;gap:10px;margin-top:6px}
.pp .offer .acts span{flex:1;min-height:48px;display:flex;align-items:center;justify-content:center;border-radius:14px;font-weight:700;border:1.5px solid var(--ink)}
.pp .offer .acts span:last-child{background:var(--navy);color:#fff;border-color:var(--navy)}
.pp .earn{display:flex;align-items:center;justify-content:space-between;background:var(--navy);border-radius:18px;padding:16px 18px;font-size:14px;color:var(--sky)}
.pp .earn b{font-size:24px;color:var(--accent)}
.pp .sample{font-size:12px;color:#8E9AB1;text-align:center}
.pp .band{background:var(--accent);color:var(--navy);text-align:center;font-weight:700;font-size:17px;padding:16px 24px}
.pp .roles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin-top:44px;list-style:none;padding:0}
.pp .role{display:flex;flex-direction:column;gap:14px;height:100%;padding:26px;border-radius:24px;background:#fff;text-decoration:none;color:var(--ink);border:2px solid var(--sand)}
.pp .role[aria-current="true"]{border-color:var(--navy)}
.pp .role h3{font-size:26px;line-height:1.1;font-weight:700}
.pp .pill{align-self:flex-start;padding:6px 14px;border-radius:999px;font-weight:700;font-size:13px;background:#EEEAE0;color:var(--muted)}
.pp .pill.live{background:var(--accent);color:var(--navy)}
.pp .role .mod{font-size:14px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--soft)}
.pp .role .bl{color:var(--muted);flex-grow:1}
.pp .role .go{display:inline-flex;align-items:center;gap:8px;font-weight:700}
.pp .how{background:#fff}
.pp .how-grid{display:flex;flex-wrap:wrap;gap:56px 48px;margin-top:48px}
.pp .steps{flex:999 1 560px;min-width:0;display:flex;flex-direction:column;gap:30px;list-style:none;padding:0}
.pp .step{display:flex;gap:22px;align-items:flex-start}
.pp .step .n{flex:0 0 auto;display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:50%;background:var(--navy);color:var(--accent);font-weight:800;font-size:26px}
.pp .step h3{font-size:24px;line-height:1.15}
.pp .step p{margin-top:6px;font-size:17px;line-height:1.55;color:var(--muted)}
.pp .need{flex:1 1 340px;min-width:0;background:var(--cream);border-radius:28px;padding:32px;align-self:flex-start}
.pp .need h3{font-size:24px}
.pp .need ul{list-style:none;margin:22px 0 0;padding:0;display:flex;flex-direction:column;gap:14px;font-size:17px}
.pp .need li{display:flex;gap:12px;align-items:flex-start}
.pp .need li svg{flex:0 0 auto;margin-top:3px}
.pp .need p{margin-top:22px;font-size:15px;color:var(--soft)}
.pp .why{background:var(--navy);color:#fff}
.pp .why .lead{color:var(--sky)}
.pp .tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin-top:48px}
.pp .tile{background:var(--navy-2);border:1.5px solid var(--line);border-radius:24px;padding:28px;display:flex;flex-direction:column;gap:14px}
.pp .tile .ic{display:flex;align-items:center;justify-content:center;width:56px;height:56px;color:var(--navy)}
.pp .tile h3{font-size:23px;line-height:1.15}
.pp .tile p{font-size:16px;line-height:1.55;color:var(--sky)}
.pp .note-line{margin-top:18px;color:var(--sky)}
.pp .faq-grid{display:flex;flex-wrap:wrap;gap:40px 64px}
.pp .faq-l{flex:1 1 320px;min-width:0}
.pp .faq-r{flex:1.6 1 480px;min-width:0;display:flex;flex-direction:column;gap:12px}
.pp .tabs{display:flex;flex-wrap:wrap;gap:10px;margin-top:28px}
.pp .tab{min-height:44px;padding:0 20px;border-radius:999px;cursor:pointer;font:inherit;font-weight:700;background:#fff;color:var(--ink);border:1.5px solid var(--edge)}
.pp .tab[aria-pressed="true"]{background:var(--navy);color:#fff;border-color:var(--navy)}
.pp .q{background:#fff;border-radius:20px;border:1.5px solid var(--sand)}
.pp .q button{width:100%;display:flex;align-items:center;justify-content:space-between;gap:18px;padding:22px 24px;min-height:44px;background:transparent;border:0;border-radius:20px;text-align:left;cursor:pointer;font:inherit;font-weight:700;font-size:18px;line-height:1.35;color:var(--ink)}
.pp .q button svg{flex:0 0 auto;transition:transform .2s}
.pp .q button[aria-expanded="true"] svg{transform:rotate(45deg)}
.pp .q p{padding:0 24px 24px;font-size:17px;line-height:1.6;color:var(--muted)}
.pp .join{background:var(--navy);color:#fff}
.pp .join-grid{display:flex;flex-wrap:wrap;gap:48px 72px;align-items:flex-start}
.pp .join-l{flex:1 1 420px;min-width:0}
.pp .join h2{font-size:clamp(36px,5vw,60px);line-height:1.02;font-weight:800;letter-spacing:-.02em}
.pp .join h2 em{font-style:normal;color:var(--accent)}
.pp .join .lead{color:var(--sky);max-width:520px;margin-top:22px}
.pp .join-points{list-style:none;margin:32px 0 0;padding:0;display:flex;flex-direction:column;gap:14px;font-size:17px;color:var(--mist)}
.pp .join-points li{display:flex;gap:12px;align-items:center}
.pp .help{margin-top:30px;color:var(--sky)}
.pp .help a{font-weight:700}
.pp .card{flex:1 1 440px;min-width:0;background:#fff;color:var(--ink);border-radius:28px;padding:32px}
.pp .form{display:flex;flex-direction:column;gap:22px}
.pp .form label.t,.pp .form legend{font-weight:700;font-size:16px;display:block;margin-bottom:8px;padding:0}
.pp .chips{display:flex;flex-wrap:wrap;gap:8px}
.pp .chip{display:inline-flex;align-items:center;min-height:44px;padding:0 18px;border-radius:999px;cursor:pointer;font-weight:700;font-size:15px;background:#fff;color:var(--ink);border:1.5px solid var(--edge)}
.pp .chips input:checked+.chip{background:var(--accent);color:var(--navy);border-color:var(--navy)}
.pp .chips input:focus-visible+.chip{outline:3px solid var(--accent);outline-offset:2px;box-shadow:0 0 0 2px var(--navy)}
.pp .sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}
.pp .inp{width:100%;min-height:52px;padding:0 16px;border-radius:14px;border:1.5px solid var(--edge);font:inherit;font-size:17px;color:var(--ink);background:#fff}
.pp .inp.err{border-color:var(--bad)}
.pp .row2{display:flex;gap:10px}
.pp .cc{display:flex;align-items:center;min-height:52px;padding:0 16px;border-radius:14px;background:var(--cream);font-weight:700;font-size:17px}
.pp .waitnote{background:var(--cream);border-radius:14px;padding:14px 16px;font-size:15px;color:var(--muted)}
.pp .cons{display:flex;align-items:flex-start;gap:12px;font-size:15px;line-height:1.45;color:var(--muted);min-height:44px}
.pp .cons input{width:22px;height:22px;margin:0;flex:0 0 auto}
.pp .msg{font-size:14px;color:var(--bad);margin-top:6px}
.pp .submit{min-height:58px;font-size:18px;width:100%}
.pp .fine{font-size:14px;color:var(--soft)}
.pp .fine a{font-weight:700}
.pp .hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.pp .done{display:flex;flex-direction:column;gap:16px}
.pp .done h3{font-size:28px}
.pp .done p{color:var(--muted);font-size:17px}
@media (max-width:900px){.pp .roles,.pp .tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.pp .roles,.pp .tiles{grid-template-columns:minmax(0,1fr)}.pp .sec{padding-block:64px}.pp .card{padding:22px}.pp .step{gap:16px}}
@media (prefers-reduced-motion:reduce){html:has(.pp){scroll-behavior:auto}.pp .q button svg{transition:none}}
</style>

<div class="pp">
    {{-- Hero with its own partner bar --}}
    <section class="hero" id="top"><div class="wrap">
        <div class="top">
            <a class="logo" href="#top"><i aria-hidden="true">1</i><b>1CallFix</b><span>Partners</span></a>
            <nav class="nav" aria-label="Page sections">
                @if ($roles !== []) <a href="#roles">Roles</a> @endif
                @if ($steps !== [] || $needs !== []) <a href="#how">How it works</a> @endif
                @if ($benefits !== []) <a href="#why">Why 1CallFix</a> @endif
                @if ($faqTabs !== []) <a href="#faq">Questions</a> @endif
            </nav>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
                <a class="btn btn-line" href="{{ route('provider.login') }}">Partner login</a>
                <a class="btn btn-accent" href="#join">Apply now</a>
            </div>
        </div>
        <div class="hero-grid">
            <div class="hero-l">
                @if (filled($heroBadge)) <div class="badge"><i aria-hidden="true"></i><span>{{ $heroBadge }}</span></div> @endif
                <h1>
                    @foreach ($heroLines as $i => $line)
                        @if ($lineCount > 1 && $i === $lineCount - 1) <em>{{ $line }}</em> @else {{ $line }}@if ($i < $lineCount - 1)<br>@endif @endif
                    @endforeach
                </h1>
                @if (filled($heroSubtitle)) <p class="sub">{{ $heroSubtitle }}</p> @endif
                <div class="ctas">
                    <a class="btn btn-accent btn-lg" href="#join">{{ $heroCta }} {!! $arrow !!}</a>
                    @if ($roles !== []) <a class="btn btn-line btn-lg" href="#roles">Find my role</a> @endif
                    @if ($androidUrl) <a href="{{ $androidUrl }}" rel="noopener" class="btn btn-line btn-lg">Get the Android app</a> @endif
                    @if ($iosUrl) <a href="{{ $iosUrl }}" rel="noopener" class="btn btn-line btn-lg">Get the iPhone app</a> @endif
                </div>
                @isset($claims['joining_free']) <p class="claim">{{ $claims['joining_free'] }}</p> @endisset
                @if ($heroTicks !== [])
                    <ul class="ticks">
                        @foreach ($heroTicks as $tick) <li><span class="chk">{!! $check !!}</span>{{ $tick }}</li> @endforeach
                    </ul>
                @endif
            </div>
            <div class="phone" role="img" aria-label="Sample of the partner app">
                <div class="row"><b style="font-size:17px">1CallFix Partner</b><span class="online"><i></i>Online</span></div>
                <div class="offer">
                    <small>New job offer</small>
                    <h3>AC service</h3>
                    <div class="meta"><span>2.4 km from you</span><span>Starts at 10:30 AM</span></div>
                    <div class="acts"><span>Reject</span><span>Accept</span></div>
                </div>
                <div class="earn"><span>Today's earnings</span><b>₹1,850</b></div>
                <div class="sample">Sample screen with example numbers</div>
            </div>
        </div>
    </div></section>

    @if (filled($band)) <div class="band">{{ $band }}</div> @endif

    {{-- Role cards (module registry) --}}
    @if ($roles !== [])
        <section class="sec" id="roles" aria-labelledby="roles-heading"><div class="wrap">
            <h2 class="h2" id="roles-heading">Pick your role</h2>
            @if (filled($rolesLead)) <p class="lead" style="color:var(--muted)">{{ $rolesLead }}</p> @endif
            <ul class="roles" x-data="{ sel: '{{ $roles[0]['code'] }}' }" x-on:partner-role-select.window="sel = $event.detail.role">
                @foreach ($roles as $role)
                    <li>
                        <a href="#join" class="role" data-role="{{ $role['code'] }}"
                           x-on:click="$dispatch('partner-role-select', { role: '{{ $role['code'] }}' })"
                           x-bind:aria-current="sel === '{{ $role['code'] }}' ? 'true' : null">
                            <span class="pill {{ $role['live'] ? 'live' : '' }}">{{ $role['live'] ? 'Live' : 'Opening soon' }}</span>
                            <h3>{{ $role['label'] }}</h3>
                            @if ($role['module'] !== '') <span class="mod">{{ $role['module'] }}</span> @endif
                            <span class="bl">{{ $role['blurb'] }}</span>
                            <span class="go">{{ $role['live'] ? 'Apply now' : 'Join the waitlist' }} {!! $arrow !!}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div></section>
    @endif

    {{-- Steps and what you will need --}}
    @if ($steps !== [] || $needs !== [])
        <section class="sec how" id="how" aria-labelledby="steps-heading"><div class="wrap">
            <h2 class="h2" id="steps-heading">From sign-up to first job in four steps</h2>
            <div class="how-grid">
                @if ($steps !== [])
                    <ol class="steps">
                        @foreach ($steps as $i => $step)
                            <li class="step">
                                <span class="n" aria-hidden="true">{{ $i + 1 }}</span>
                                <div><h3><span class="sr">Step {{ $i + 1 }}: </span>{{ $step['title'] }}</h3><p>{{ $step['body'] }}</p></div>
                            </li>
                        @endforeach
                    </ol>
                @endif
                @if ($needs !== [] || filled($approvalTime) || isset($claims['save_finish_later']))
                    <div class="need">
                        @if ($needs !== [])
                            <h3>What you will need</h3>
                            <ul>@foreach ($needs as $need) <li>{!! $check !!}<span>{{ $need }}</span></li> @endforeach</ul>
                        @endif
                        @if (filled($approvalTime)) <p>{{ $approvalTime }}</p> @endif
                        @isset($claims['save_finish_later']) <p>{{ $claims['save_finish_later'] }}</p> @endisset
                    </div>
                @endif
            </div>
        </div></section>
    @endif

    {{-- Benefits --}}
    @if ($benefits !== [])
        <section class="sec why" id="why" aria-labelledby="benefits-heading"><div class="wrap">
            <h2 class="h2" id="benefits-heading">Built so partners can do their best work</h2>
            <div class="tiles">
                @foreach ($benefits as $i => $b)
                    <div class="tile">
                        <span class="ic" aria-hidden="true" style="background:{{ $tones[$b['color']] ?? $tones['amber'] }};{{ $shapes[$i % 6] }}"><x-icon :name="$b['icon']" class="h-7 w-7" /></span>
                        <h3>{{ $b['title'] }}</h3>
                        <p>{{ $b['body'] }}</p>
                    </div>
                @endforeach
            </div>
            @foreach (['company_accounts', 'whatsapp_updates'] as $claim)
                @isset($claims[$claim]) <p class="note-line">{{ $claims[$claim] }}</p> @endisset
            @endforeach
            @if (filled($commission) || filled($payoutTiming))
                <div class="note-line">
                    @if (filled($commission)) <p>{{ $commission }}</p> @endif
                    @if (filled($payoutTiming)) <p>{{ $payoutTiming }}</p> @endif
                </div>
            @endif
        </div></section>
    @elseif (filled($commission) || filled($payoutTiming))
        <section class="sec"><div class="wrap">
            @if (filled($commission)) <p>{{ $commission }}</p> @endif
            @if (filled($payoutTiming)) <p>{{ $payoutTiming }}</p> @endif
        </div></section>
    @endif

    {{-- FAQ (tabs + single-open accordion) --}}
    @if ($faqTabs !== [])
        <section class="sec" id="faq" aria-labelledby="faq-heading" x-data="{ tab: '{{ $firstTab }}', open: '{{ $firstTab }}-0' }"><div class="wrap">
            <div class="faq-grid">
                <div class="faq-l">
                    <h2 class="h2" id="faq-heading">Every question, one place</h2>
                    <p class="lead" style="color:var(--muted)">Pick your group to see the answers that matter to you.</p>
                    <div class="tabs" role="group" aria-label="Question groups">
                        @foreach ($faqTabs as $key => $group)
                            <button type="button" class="tab" id="faq-tab-{{ $key }}" x-on:click="tab = '{{ $key }}'; open = ''"
                                    x-bind:aria-pressed="tab === '{{ $key }}' ? 'true' : 'false'" aria-pressed="{{ $key === $firstTab ? 'true' : 'false' }}">{{ $group['label'] }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="faq-r">
                    @foreach ($faqTabs as $key => $group)
                        @foreach ($group['items'] as $n => $item)
                            @php $qid = $key.'-'.$n; @endphp
                            <div class="q" data-faq-tab="{{ $key }}" x-show="tab === '{{ $key }}'" @if ($key !== $firstTab) x-cloak @endif>
                                <button type="button" aria-controls="faq-a-{{ $qid }}" x-on:click="open = (open === '{{ $qid }}' ? '' : '{{ $qid }}')"
                                        x-bind:aria-expanded="open === '{{ $qid }}' ? 'true' : 'false'" aria-expanded="{{ $key === $firstTab && $n === 0 ? 'true' : 'false' }}">
                                    <span>{{ $item['q'] }}</span>
                                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                </button>
                                <p id="faq-a-{{ $qid }}" x-show="open === '{{ $qid }}'" @if (! ($key === $firstTab && $n === 0)) x-cloak @endif>{{ $item['a'] }}</p>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div></section>
    @endif

    {{-- Application form --}}
    <section class="sec join" id="join" aria-labelledby="apply-heading"><div class="wrap">
        <div class="join-grid">
            <div class="join-l">
                <h2 id="apply-heading">Ready? Start your <em>application</em>.</h2>
                @if (filled($joinLead)) <p class="lead">{{ $joinLead }}</p> @endif
                <ul class="join-points">
                    @isset($claims['joining_free']) <li><span class="chk">{!! $check !!}</span>{{ $claims['joining_free'] }} No fee to apply.</li> @endisset
                    <li><span class="chk">{!! $check !!}</span>You finish your application in the 1CallFix app</li>
                    @isset($claims['save_finish_later']) <li><span class="chk">{!! $check !!}</span>Save your progress and finish later</li> @endisset
                    <li><span class="chk">{!! $check !!}</span>Status updates by notification when your application moves</li>
                </ul>
                @if ($phones !== [])
                    <p class="help">Need a hand applying? Contact partner support on
                        @foreach ($phones as $phone)@if ($phone['tel'] !== '')<a href="tel:{{ $phone['tel'] }}">{{ $phone['text'] }}</a>@else {{ $phone['text'] }}@endif @if (! $loop->last) or @endif @endforeach.
                    </p>
                @endif
                <p class="help">Already a partner? <a href="{{ route('provider.login') }}">Sign in</a></p>
            </div>
            <div class="card"><livewire:partner.apply-form /></div>
        </div>
    </div></section>
</div>
</x-layouts.customer>
