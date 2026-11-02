<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nguyễn Hữu Đức — Product Management Leader</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-0: #07070b;
            --bg-1: #0c0d14;
            --bg-2: #11131c;
            --ink: #f4f4f6;
            --muted: #9aa0ad;
            --line: rgba(255,255,255,0.08);
            --accent: #ff6b35;
            --accent-2: #ffb86b;
            --cyan: #54e6ff;
            --violet: #a07bff;
            --green: #4ade80;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bg-0);
            color: var(--ink);
            line-height: 1.6;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* Background grain & gradients */
        .bg-fx {
            position: fixed; inset: 0; z-index: -1; pointer-events: none;
            background:
                    radial-gradient(1200px 600px at 80% -10%, rgba(255,107,53,0.18), transparent 60%),
                    radial-gradient(900px 500px at -10% 20%, rgba(160,123,255,0.15), transparent 60%),
                    radial-gradient(800px 600px at 50% 110%, rgba(84,230,255,0.10), transparent 60%),
                    var(--bg-0);
        }
        .bg-fx::after {
            content: ""; position: absolute; inset: 0;
            background-image:
                    linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
            background-size: 56px 56px;
            mask-image: radial-gradient(ellipse 80% 60% at 50% 30%, black, transparent 70%);
        }

        /* Nav */
        nav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 50;
            padding: 18px 40px;
            display: flex; align-items: center; justify-content: space-between;
            backdrop-filter: blur(14px);
            background: rgba(7,7,11,0.55);
            border-bottom: 1px solid var(--line);
        }
        .logo {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700; font-size: 18px; letter-spacing: -0.02em;
            display: flex; align-items: center; gap: 10px;
        }
        .logo-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 14px var(--accent);
            animation: pulse 2s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.2); }
        }
        .nav-links { display: flex; gap: 32px; list-style: none; }
        .nav-links a {
            color: var(--muted); text-decoration: none; font-size: 14px;
            font-weight: 500; transition: color 0.2s;
        }
        .nav-links a:hover { color: var(--ink); }

        /* Container */
        .container { max-width: 1200px; margin: 0 auto; padding: 0 40px; }

        /* HERO */
        .hero {
            min-height: 100vh;
            display: flex; align-items: center;
            padding: 120px 40px 80px;
            position: relative;
        }
        .hero-grid {
            max-width: 1200px; width: 100%; margin: 0 auto;
            display: grid; grid-template-columns: 1.3fr 1fr; gap: 80px;
            align-items: center;
        }
        .hero-tag {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 8px 14px; border-radius: 100px;
            background: rgba(255,107,53,0.1);
            border: 1px solid rgba(255,107,53,0.25);
            font-size: 13px; font-weight: 500; color: var(--accent-2);
            margin-bottom: 28px;
        }
        .hero-tag .dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 8px var(--green);
        }
        .hero h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(48px, 7vw, 92px);
            font-weight: 700;
            line-height: 1.02;
            letter-spacing: -0.04em;
            margin-bottom: 24px;
        }
        .hero h1 .gradient {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 50%, var(--violet) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            display: inline-block;
        }
        .hero-sub {
            font-size: 19px; color: var(--muted);
            margin-bottom: 36px; max-width: 540px;
            line-height: 1.7;
        }
        .hero-stats {
            display: flex; gap: 36px; margin-bottom: 40px; flex-wrap: wrap;
        }
        .stat-item .num {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 36px; font-weight: 700;
            color: var(--ink); line-height: 1;
            margin-bottom: 6px;
        }
        .stat-item .num span { color: var(--accent); }
        .stat-item .label {
            font-size: 13px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.1em;
        }
        .cta-row { display: flex; gap: 14px; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 14px 24px; border-radius: 100px;
            font-size: 15px; font-weight: 600; text-decoration: none;
            transition: all 0.25s ease;
            cursor: pointer; border: none;
            font-family: inherit;
        }
        .btn-primary {
            background: var(--ink); color: var(--bg-0);
        }
        .btn-primary:hover {
            background: var(--accent); color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(255,107,53,0.35);
        }
        .btn-ghost {
            background: transparent; color: var(--ink);
            border: 1px solid var(--line);
        }
        .btn-ghost:hover {
            background: rgba(255,255,255,0.05);
            border-color: rgba(255,255,255,0.2);
        }

        /* Hero portrait card */
        .portrait {
            position: relative;
            aspect-ratio: 4/5;
            border-radius: 24px;
            background: linear-gradient(145deg, var(--bg-2), var(--bg-1));
            border: 1px solid var(--line);
            overflow: hidden;
            padding: 28px;
            display: flex; flex-direction: column; justify-content: space-between;
        }
        .portrait::before {
            content: "";
            position: absolute; inset: 0;
            background:
                    radial-gradient(400px 300px at 80% 20%, rgba(255,107,53,0.25), transparent 60%),
                    radial-gradient(400px 300px at 20% 80%, rgba(160,123,255,0.2), transparent 60%);
            pointer-events: none;
        }
        .portrait-avatar {
            width: 100%; aspect-ratio: 1; border-radius: 16px;
            background: linear-gradient(135deg, #1a1d2a, #0c0e16);
            display: flex; align-items: center; justify-content: center;
            position: relative; overflow: hidden;
            border: 1px solid var(--line);
        }
        .avatar-photo {
            position: absolute; inset: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            object-position: center top;
            z-index: 0;
            transition: transform 0.6s ease;
        }
        .portrait:hover .avatar-photo { transform: scale(1.04); }
        .avatar-photo-overlay {
            position: absolute; inset: 0; z-index: 1;
            background:
                    linear-gradient(180deg, transparent 50%, rgba(7,7,11,0.55) 100%),
                    radial-gradient(circle at 50% 50%, transparent 60%, rgba(255,107,53,0.08) 95%);
            pointer-events: none;
        }
        .avatar-badge {
            position: absolute; left: 14px; bottom: 14px; z-index: 2;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 12px; border-radius: 100px;
            background: rgba(7,7,11,0.6);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.12);
            font-size: 11px; font-weight: 600; color: var(--ink);
            letter-spacing: 0.05em; text-transform: uppercase;
        }
        .avatar-badge .dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--green); box-shadow: 0 0 8px var(--green);
        }
        .portrait-meta {
            position: relative; z-index: 1;
            display: flex; flex-direction: column; gap: 14px;
            margin-top: 24px;
        }
        .meta-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--line);
            border-radius: 12px;
            font-size: 13px;
        }
        .meta-row .key { color: var(--muted); }
        .meta-row .val { color: var(--ink); font-weight: 500; }

        /* SECTION */
        section { padding: 120px 40px; position: relative; }
        .section-head { margin-bottom: 64px; max-width: 720px; }
        .section-tag {
            display: inline-block;
            font-size: 13px; font-weight: 600; color: var(--accent);
            text-transform: uppercase; letter-spacing: 0.2em;
            margin-bottom: 16px;
        }
        .section-head h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(36px, 5vw, 56px);
            font-weight: 700; line-height: 1.1;
            letter-spacing: -0.03em;
            margin-bottom: 16px;
        }
        .section-head p { color: var(--muted); font-size: 17px; max-width: 600px; }

        /* About */
        .about-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 32px;
        }
        .about-card {
            padding: 36px;
            background: linear-gradient(145deg, var(--bg-1), var(--bg-2));
            border: 1px solid var(--line);
            border-radius: 20px;
            transition: all 0.3s ease;
            position: relative; overflow: hidden;
        }
        .about-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255,107,53,0.2);
        }
        .about-card h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 24px; font-weight: 600;
            margin-bottom: 14px;
            letter-spacing: -0.02em;
        }
        .about-card p { color: var(--muted); font-size: 15px; line-height: 1.7; }
        .about-card .icon {
            width: 48px; height: 48px; border-radius: 12px;
            background: rgba(255,107,53,0.12);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 24px;
            font-size: 24px;
        }

        /* Expertise */
        .expertise-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px;
        }
        .skill-card {
            padding: 32px 28px;
            background: var(--bg-1);
            border: 1px solid var(--line);
            border-radius: 16px;
            transition: all 0.3s ease;
        }
        .skill-card:hover {
            background: var(--bg-2);
            transform: translateY(-3px);
            border-color: rgba(255,255,255,0.15);
        }
        .skill-card .num {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 14px; color: var(--accent);
            font-weight: 600; letter-spacing: 0.1em;
            margin-bottom: 16px;
        }
        .skill-card h4 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 22px; font-weight: 600;
            margin-bottom: 12px; letter-spacing: -0.02em;
        }
        .skill-card p { color: var(--muted); font-size: 14px; line-height: 1.7; }
        .tags { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 20px; }
        .tag {
            padding: 6px 12px; border-radius: 100px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--line);
            font-size: 12px; color: var(--ink);
        }

        /* Journey timeline */
        .journey {
            background: linear-gradient(180deg, transparent, rgba(255,107,53,0.04), transparent);
        }
        .timeline {
            position: relative;
            padding-left: 32px;
        }
        .timeline::before {
            content: "";
            position: absolute; left: 11px; top: 12px; bottom: 12px;
            width: 2px;
            background: linear-gradient(to bottom, var(--accent), transparent);
        }
        .timeline-item {
            position: relative;
            padding: 24px 32px;
            margin-bottom: 24px;
            background: var(--bg-1);
            border: 1px solid var(--line);
            border-radius: 16px;
            transition: all 0.3s ease;
        }
        .timeline-item:hover {
            border-color: rgba(255,107,53,0.3);
            transform: translateX(6px);
        }
        .timeline-item::before {
            content: "";
            position: absolute; left: -29px; top: 28px;
            width: 12px; height: 12px; border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 0 4px var(--bg-0), 0 0 18px var(--accent);
        }
        .timeline-date {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 13px; color: var(--accent);
            font-weight: 600; margin-bottom: 8px;
            letter-spacing: 0.05em;
        }
        .timeline-item h4 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 20px; font-weight: 600;
            margin-bottom: 6px;
        }
        .timeline-item p { color: var(--muted); font-size: 14px; }

        /* Running */
        .running-grid {
            display: grid; grid-template-columns: 1.2fr 1fr; gap: 48px;
            align-items: center;
        }
        .running-card {
            padding: 40px;
            background: linear-gradient(145deg, #1a0e08, #0e0a16);
            border: 1px solid rgba(255,107,53,0.15);
            border-radius: 24px;
            position: relative; overflow: hidden;
        }
        .running-card::before {
            content: "";
            position: absolute; top: -50%; right: -20%;
            width: 400px; height: 400px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,107,53,0.15), transparent 60%);
            pointer-events: none;
        }
        .race-target {
            position: relative; z-index: 1;
        }
        .race-target .label {
            font-size: 13px; font-weight: 600;
            color: var(--accent-2);
            text-transform: uppercase; letter-spacing: 0.15em;
            margin-bottom: 12px;
        }
        .race-target h3 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 48px; font-weight: 700;
            line-height: 1.1; letter-spacing: -0.03em;
            margin-bottom: 24px;
        }
        .race-target h3 .accent {
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .race-stats {
            display: grid; grid-template-columns: repeat(2,1fr);
            gap: 16px; margin-top: 32px;
        }
        .race-stat {
            padding: 18px;
            background: rgba(255,255,255,0.03);
            border: 1px solid var(--line);
            border-radius: 12px;
        }
        .race-stat .v {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 28px; font-weight: 700;
            color: var(--ink); line-height: 1;
            margin-bottom: 6px;
        }
        .race-stat .k {
            font-size: 12px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.1em;
        }

        /* Contact */
        .contact {
            text-align: center;
            padding: 140px 40px;
        }
        .contact h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: clamp(40px, 6vw, 72px);
            font-weight: 700; line-height: 1.05;
            letter-spacing: -0.04em;
            margin-bottom: 24px;
        }
        .contact h2 .gradient {
            background: linear-gradient(135deg, var(--accent), var(--accent-2), var(--violet));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .contact p {
            color: var(--muted); font-size: 18px;
            max-width: 560px; margin: 0 auto 48px;
        }
        .contact-grid {
            display: grid; grid-template-columns: repeat(3,1fr);
            gap: 18px; max-width: 720px; margin: 0 auto;
        }
        .contact-card {
            padding: 28px 22px;
            background: var(--bg-1);
            border: 1px solid var(--line);
            border-radius: 16px;
            text-decoration: none; color: var(--ink);
            transition: all 0.25s ease;
            display: flex; flex-direction: column;
            align-items: center; text-align: center; gap: 10px;
        }
        .contact-card:hover {
            border-color: var(--accent);
            transform: translateY(-4px);
            background: var(--bg-2);
        }
        .contact-card .ico {
            width: 44px; height: 44px; border-radius: 50%;
            background: rgba(255,107,53,0.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; margin-bottom: 6px;
        }
        .contact-card .k {
            font-size: 12px; color: var(--muted);
            text-transform: uppercase; letter-spacing: 0.1em;
        }
        .contact-card .v {
            font-size: 15px; font-weight: 500;
            word-break: break-all;
        }

        footer {
            padding: 32px 40px;
            text-align: center;
            border-top: 1px solid var(--line);
            color: var(--muted); font-size: 13px;
        }

        /* Reveal animation */
        .reveal {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.7s ease, transform 0.7s ease;
        }
        .reveal.in {
            opacity: 1;
            transform: translateY(0);
        }

        /* Responsive */
        @media (max-width: 900px) {
            nav { padding: 14px 20px; }
            .nav-links { display: none; }
            .container, section, .hero { padding-left: 20px; padding-right: 20px; }
            .hero-grid { grid-template-columns: 1fr; gap: 48px; }
            .about-grid, .expertise-grid, .running-grid { grid-template-columns: 1fr; }
            .contact-grid { grid-template-columns: 1fr; }
            .hero { padding-top: 100px; }
            section { padding: 80px 20px; }
        }
    </style>
</head>
<body>
<div class="bg-fx"></div>

<nav>
    <div class="logo">
        <span class="logo-dot"></span>
        <span>NGUYỄN ĐỨC</span>
    </div>
    @php
        // Menu "main" quản lý ở backend; chưa có thì dùng các mục mặc định. Thanh nav phẳng nên chỉ hiện cấp 1.
        $navItems = $menu ?? [
            ['label' => 'Giới thiệu', 'url' => '#about'], ['label' => 'Chuyên môn', 'url' => '#expertise'], ['label' => 'Hành trình', 'url' => '#journey'],
            ['label' => 'Chạy bộ', 'url' => '#running'], ['label' => 'Liên hệ', 'url' => '#contact'],
        ];
    @endphp
    <ul class="nav-links">
        @foreach ($navItems as $item)
            @php
                // Link đã được validate ở backend; chặn thêm scheme nguy hiểm cho chắc. Link ngoài (http(s):// hoặc //) mở tab mới.
                $url = trim((string) $item['url']);
                $url = $url === '' || preg_match('/^\s*(javascript|data|vbscript):/i', $url) ? '#' : $url;
                $external = (bool) preg_match('#^(https?:)?//#i', $url);
            @endphp
            <li><a href="{{ $url }}"@if ($external) target="_blank" rel="noopener"@endif>{{ $item['label'] }}</a></li>
        @endforeach
    </ul>
</nav>

<!-- HERO -->
<section class="hero">
    <div class="hero-grid">
        <div>
            <div class="hero-tag reveal">
                <span class="dot"></span>
                Available · PM Lead at work · Hà Nội, Việt Nam
            </div>
            <h1 class="reveal">
                Xin chào, tôi là<br/>
                <span class="gradient">Nguyễn Đức</span>
            </h1>
            <p class="hero-sub reveal">
                Product Management Leader với hơn 10 năm kinh nghiệm trong ngành kỹ thuật và quản lý sản phẩm.
                Tôi xây dựng các sản phẩm tốt từ sự giao thoa giữa tư duy kỹ thuật vững chắc, vận hành đội ngũ
                kỷ luật và tinh thần của một người chạy đường trường.
            </p>

            <div class="hero-stats reveal">
                <div class="stat-item">
                    <div class="num"><span>10+</span></div>
                    <div class="label">Năm kinh nghiệm</div>
                </div>
                <div class="stat-item">
                    <div class="num"><span>5</span></div>
                    <div class="label">Thành viên team</div>
                </div>
                <div class="stat-item">
                    <div class="num">Sub <span>2h</span></div>
                    <div class="label">Mục tiêu HM</div>
                </div>
            </div>

            <div class="cta-row reveal">
                <a href="#contact" class="btn btn-primary">
                    Liên hệ với tôi
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14m-7-7l7 7-7 7"/></svg>
                </a>
                <a href="#about" class="btn btn-ghost">
                    Tìm hiểu thêm
                </a>
            </div>
        </div>

        <div class="reveal">
            <div class="portrait">
                <div class="portrait-avatar">
                    <img class="avatar-photo" src="{{ asset('assets/frontend/images/avatar.png') }}" alt="Nguyễn Hữu Đức" />
                    <div class="avatar-photo-overlay"></div>
                    <div class="avatar-badge"><span class="dot"></span> Nguyễn Đức</div>
                </div>
                <div class="portrait-meta">
                    <div class="meta-row">
                        <span class="key">Vai trò</span>
                        <span class="val">PM Lead</span>
                    </div>
                    <div class="meta-row">
                        <span class="key">Cư trú</span>
                        <span class="val">Hà Nội, VN</span>
                    </div>
                    <div class="meta-row">
                        <span class="key">Sinh nhật</span>
                        <span class="val">24 / 01 / 1991</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ABOUT -->
<section id="about">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">— Về tôi</span>
            <h2>Một người làm sản phẩm với gốc rễ kỹ thuật</h2>
            <p>Tôi tin rằng những sản phẩm có sức bền được tạo nên từ sự kết hợp giữa hiểu biết kỹ thuật sâu, tư duy hệ thống và một văn hoá đội ngũ rõ ràng.</p>
        </div>

        <div class="about-grid">
            <div class="about-card reveal">
                <div class="icon">🎯</div>
                <h3>Từ DEV Lead đến PM Lead</h3>
                <p>
                    Sau hơn một thập kỷ với vai trò kỹ thuật, tháng 4/2026 tôi chính thức chuyển sang vị trí
                    Product Management Leader — quản lý đội ngũ gồm 2 Business Analyst và 2 UI/UX Designer,
                    tập trung vào việc đưa sản phẩm từ ý tưởng tới phiên bản dùng được, ổn định và đo được.
                </p>
            </div>

            <div class="about-card reveal">
                <div class="icon">⚙️</div>
                <h3>Đứng giữa code và sản phẩm</h3>
                <p>
                    Tôi vẫn viết code, vẫn đọc database schema, vẫn debug xác thực PHP/Laravel khi cần.
                    Khả năng nói cùng ngôn ngữ với engineer giúp tôi ra quyết định sản phẩm sát thực tế
                    và rút ngắn rất nhiều vòng lặp giao tiếp.
                </p>
            </div>

            <div class="about-card reveal">
                <div class="icon">🏃</div>
                <h3>Chạy đường trường, làm sản phẩm dài hơi</h3>
                <p>
                    Marathon dạy tôi kỷ luật của người làm sản phẩm: tin vào quá trình, kiểm soát nhịp độ,
                    không bị cuốn theo cảm xúc của km đầu tiên. Mục tiêu Sub 2 cho Half Marathon ngày 17/05/2026
                    là cách tôi giữ phong độ — cả thể chất lẫn tư duy.
                </p>
            </div>

            <div class="about-card reveal">
                <div class="icon">📐</div>
                <h3>Triết lý làm việc</h3>
                <p>
                    Chính xác trong xử lý kỹ thuật, công bằng trong vận hành đội ngũ. Tôi xây dựng hệ thống
                    đánh giá theo điểm để công sức và kết quả của mỗi thành viên đều được nhìn thấy rõ ràng,
                    minh bạch và có cơ sở để cải thiện.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- EXPERTISE -->
<section id="expertise">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">— Chuyên môn</span>
            <h2>Những gì tôi làm tốt</h2>
            <p>Pha trộn giữa product leadership, kiến trúc kỹ thuật và hiểu biết ngành dọc.</p>
        </div>

        <div class="expertise-grid">
            <div class="skill-card reveal">
                <div class="num">01 / Product Leadership</div>
                <h4>Quản lý sản phẩm & đội ngũ</h4>
                <p>Định hướng roadmap, chia nhỏ vấn đề, ưu tiên backlog, đo lường hiệu quả. Vận hành đội 5 người gồm BA & UI/UX Designer theo hệ thống đánh giá dựa trên điểm.</p>
                <div class="tags">
                    <span class="tag">Roadmap</span>
                    <span class="tag">Prioritization</span>
                    <span class="tag">Team Ops</span>
                    <span class="tag">Point-based KPI</span>
                </div>
            </div>

            <div class="skill-card reveal">
                <div class="num">02 / Engineering</div>
                <h4>Web platform & system design</h4>
                <p>Thiết kế kiến trúc web, mô hình hoá database, xác thực và phân quyền PHP/Laravel. Tự chủ deploy với Docker, aaPanel cho các môi trường thực tế.</p>
                <div class="tags">
                    <span class="tag">PHP</span>
                    <span class="tag">Laravel</span>
                    <span class="tag">Docker</span>
                    <span class="tag">aaPanel</span>
                    <span class="tag">DB Design</span>
                </div>
            </div>

            <div class="skill-card reveal">
                <div class="num">03 / Print-on-Demand</div>
                <h4>POD platform & SVG automation</h4>
                <p>Hiểu sâu luồng đơn hàng POD: dựng mockup, hiệu ứng lọc ảnh, thao tác SVG động và tự động hoá xử lý hình ảnh ở quy mô. Tối ưu cả phần kỹ thuật lẫn trải nghiệm người dùng.</p>
                <div class="tags">
                    <span class="tag">SVG</span>
                    <span class="tag">Mockup Engine</span>
                    <span class="tag">Image Pipeline</span>
                    <span class="tag">POD</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- JOURNEY -->
<section id="journey" class="journey">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">— Hành trình gần đây</span>
            <h2>Những cột mốc đáng nhớ</h2>
            <p>Vài việc đang chiếm phần lớn năng lượng của tôi trong giai đoạn này.</p>
        </div>

        <div class="timeline">
            <div class="timeline-item reveal">
                <div class="timeline-date">04 / 2026 — Hiện tại</div>
                <h4>Đảm nhận vai trò Product Management Leader</h4>
                <p>Bước chuyển từ DEV Lead sang PM Lead, dẫn dắt đội ngũ 2 BA + 2 UI/UX Designer, định hình lại quy trình sản phẩm và đánh giá nhân sự.</p>
            </div>
            <div class="timeline-item reveal">
                <div class="timeline-date">17 / 05 / 2026</div>
                <h4>Half Marathon — Mục tiêu Sub 2</h4>
                <p>Chuẩn bị cho ngày đua chính thức với mục tiêu hoàn thành 21,0975 km dưới 2 giờ. Theo dõi tải tập bằng Garmin 965, tinh chỉnh ground contact time và pacing.</p>
            </div>
            <div class="timeline-item reveal">
                <div class="timeline-date">03 — 04 / 2026</div>
                <h4>Hệ thống tracking dấu chân người dùng</h4>
                <p>Thiết kế DB schema và logic xác thực PHP cho hệ thống theo dõi hành vi và điều hướng sản phẩm trên nền tảng web — phục vụ phân tích và cá nhân hoá.</p>
            </div>
            <div class="timeline-item reveal">
                <div class="timeline-date">12 / 2025 — 04 / 2026</div>
                <h4>Tư vấn kiến trúc nền tảng POD</h4>
                <p>Đi sâu vào dựng mockup, hiệu ứng lọc, luồng xử lý đơn hàng cho các nền tảng Print-on-Demand. Tập trung vào hiệu năng và tính đúng đắn của output.</p>
            </div>
        </div>
    </div>
</section>

<!-- RUNNING -->
<section id="running">
    <div class="container">
        <div class="section-head reveal">
            <span class="section-tag">— Off-keyboard</span>
            <h2>Khi không ngồi trước màn hình, tôi chạy</h2>
            <p>Marathon là cách tôi rèn lại tư duy: tin vào quá trình, đi đường dài, kiểm soát nhịp độ.</p>
        </div>

        <div class="running-grid">
            <div class="running-card reveal">
                <div class="race-target">
                    <div class="label">Race Target · 17/05/2026</div>
                    <h3>Half Marathon<br/><span class="accent">Sub 2 hours</span></h3>
                    <p style="color: var(--muted); font-size: 15px; max-width: 460px;">
                        21,0975 km. Pace mục tiêu trung bình ~5:40/km. Theo dõi tập luyện qua Garmin 965, tập trung
                        vào ground contact time, cadence và phục hồi giữa các buổi.
                    </p>
                    <div class="race-stats">
                        <div class="race-stat">
                            <div class="v">21.1<span style="font-size:16px;color:var(--muted);"> km</span></div>
                            <div class="k">Distance</div>
                        </div>
                        <div class="race-stat">
                            <div class="v">< 2h</div>
                            <div class="k">Goal Time</div>
                        </div>
                        <div class="race-stat">
                            <div class="v">~5:40<span style="font-size:14px;color:var(--muted);">/km</span></div>
                            <div class="k">Target Pace</div>
                        </div>
                        <div class="race-stat">
                            <div class="v">Garmin 965</div>
                            <div class="k">Daily Driver</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="reveal">
                <div class="about-card" style="background: linear-gradient(145deg,#0d0f18,#0a0c14);">
                    <div class="icon">🛠️</div>
                    <h3>Daily tech stack</h3>
                    <p style="margin-bottom: 18px;">
                        Những công cụ đồng hành cùng tôi mỗi ngày — từ bàn làm việc đến đường chạy.
                    </p>
                    <div class="tags">
                        <span class="tag">MacBook Pro M1</span>
                        <span class="tag">iPhone 15 Pro Max</span>
                        <span class="tag">Garmin Forerunner 965</span>
                        <span class="tag">VS Code</span>
                        <span class="tag">Docker</span>
                        <span class="tag">Notion</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT -->
<section id="contact" class="contact">
    <div class="container">
        <h2 class="reveal">
            Hãy cùng <span class="gradient">tạo ra điều gì đó</span><br/>có ý nghĩa.
        </h2>
        <p class="reveal">
            Nếu bạn đang xây dựng sản phẩm, cần một góc nhìn từ người làm cả product lẫn engineering,
            hoặc đơn giản muốn rủ tôi đi chạy — tôi luôn sẵn sàng.
        </p>
        <div class="contact-grid">
            <a class="contact-card reveal" href="tel:0968057988">
                <div class="ico">📞</div>
                <div class="k">Phone</div>
                <div class="v">0968 057 988</div>
            </a>
            <a class="contact-card reveal" href="https://t.me/ducnh241" target="_blank" rel="noopener">
                <div class="ico">✈️</div>
                <div class="k">Telegram</div>
                <div class="v">@ducnh241</div>
            </a>
            <a class="contact-card reveal" href="#">
                <div class="ico">📍</div>
                <div class="k">Location</div>
                <div class="v">Hà Nội, Việt Nam</div>
            </a>
        </div>
    </div>
</section>

<footer>
    © 2026 Nguyễn Hữu Đức · Crafted with care, kilometers, and a lot of coffee.
</footer>

<script>
    // Reveal on scroll
    const io = new IntersectionObserver((entries) => {
        entries.forEach((e) => {
            if (e.isIntersecting) {
                e.target.classList.add('in');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach((el) => io.observe(el));

    // Stagger hero reveals
    document.querySelectorAll('.hero .reveal').forEach((el, i) => {
        el.style.transitionDelay = (i * 90) + 'ms';
        setTimeout(() => el.classList.add('in'), 100 + i * 90);
    });
</script>
</body>
</html>
