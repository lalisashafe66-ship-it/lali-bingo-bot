<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>Smart Bingo — Play &amp; Win</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <style>
    :root {
      --bg-deep: #0b2429;
      --bg-card: #12363d;
      --bg-card-soft: #194750;
      --accent-gold: #f5b700;
      --accent-gold-hover: #ffcc29;
      --accent-green: #22c55e;
      --text-main: #f8fafc;
      --text-muted: #9ec0c7;
      --line-border: rgba(255, 255, 255, 0.12);
    }

    *, *::before, *::after {
      box-sizing: border-box;
      -webkit-tap-highlight-color: transparent;
    }

    body {
      margin: 0;
      padding: 0 0 92px 0;
      background: radial-gradient(circle at top, #17434b 0%, var(--bg-deep) 70%);
      color: var(--text-main);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      min-height: 100vh;
    }

    .top-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 16px;
      background: rgba(11, 36, 41, 0.94);
      backdrop-filter: blur(10px);
      position: sticky;
      top: 0;
      z-index: 40;
    }

    .top-stripe {
      height: 8px;
      background: repeating-linear-gradient(
        135deg,
        var(--accent-gold) 0px,
        var(--accent-gold) 9px,
        #1e606e 9px,
        #1e606e 18px
      );
    }

    .brand-pill {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 900;
      font-size: 1.08rem;
    }

    .brand-diamond {
      width: 13px;
      height: 13px;
      transform: rotate(45deg);
      background: var(--accent-gold);
      box-shadow: 0 0 10px rgba(245, 183, 0, 0.65);
    }

    .balance-box {
      text-align: right;
      cursor: pointer;
    }

    .balance-label {
      display: block;
      font-size: 0.7rem;
      color: var(--text-muted);
      line-height: 1.1;
    }

    .balance-pill {
      background: transparent;
      border: none;
      color: var(--accent-gold);
      font-weight: 900;
      font-size: 1.02rem;
      padding: 0;
      cursor: pointer;
    }

    .section-title {
      font-size: 1.55rem;
      font-weight: 900;
      margin: 14px 0 6px;
      line-height: 1.15;
    }

    .section-sub {
      color: var(--text-muted);
      font-size: 0.92rem;
      margin-bottom: 14px;
      line-height: 1.4;
    }

    .onboarding-strip {
      background: var(--bg-card);
      border: 1px solid rgba(245, 183, 0, 0.35);
      border-radius: 16px;
      padding: 12px 14px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }

    .onboarding-step-text {
      font-size: 0.82rem;
      color: var(--text-main);
      font-weight: 700;
      flex: 1;
      min-width: 180px;
    }

    .onboarding-btns {
      display: flex;
      gap: 8px;
    }

    .card-panel {
      background: var(--bg-card);
      border: 1px solid var(--line-border);
      border-radius: 18px;
      padding: 14px;
      margin-bottom: 14px;
    }

    .table-card {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: var(--bg-card);
      border: 2px solid var(--line-border);
      border-radius: 16px;
      padding: 14px 16px;
      margin-bottom: 12px;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .table-card.selected {
      border-color: var(--accent-gold);
      background: rgba(245, 183, 0, 0.12);
    }

    .table-card input[type="radio"] { display: none; }

    .table-big-amt {
      font-size: 2.1rem;
      font-weight: 900;
      line-height: 1;
      min-width: 52px;
      text-align: center;
    }

    .table-meta-title {
      font-weight: 900;
      font-size: 1rem;
      color: #fff;
    }

    .table-meta-sub {
      font-size: 0.82rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .radio-ring {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      border: 2px solid var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .table-card.selected .radio-ring { border-color: var(--accent-gold); }

    .table-card.selected .radio-ring::after {
      content: "";
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: var(--accent-gold);
    }

    .legend-row {
      display: flex;
      align-items: center;
      gap: 18px;
      font-size: 0.85rem;
      font-weight: 700;
      margin: 4px 0 10px;
    }

    .legend-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 6px;
      vertical-align: middle;
    }

    .legend-dot.yours { background: var(--accent-gold); }
    .legend-dot.taken { background: var(--bg-card-soft); border: 1px solid var(--line-border); }

    .cartela-picker-scroll {
      max-height: 250px;
      overflow-y: auto;
      padding: 6px;
      border-radius: 14px;
      background: rgba(6, 22, 26, 0.45);
    }

    .cartela-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 6px;
    }

    .cartela-circle {
      aspect-ratio: 1 / 1;
      border-radius: 50%;
      background: var(--bg-card-soft);
      border: 1px solid var(--line-border);
      color: var(--text-main);
      font-size: 0.78rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      user-select: none;
    }

    .cartela-circle.selected {
      background: var(--accent-gold);
      color: #111827;
      border-color: #fff;
      font-weight: 900;
    }

    .cartela-circle.taken {
      background: rgba(100, 116, 139, 0.16);
      color: #64748b;
      cursor: not-allowed;
      text-decoration: line-through;
    }

    .mini-preview-strip {
      display: flex;
      gap: 10px;
      overflow-x: auto;
      padding-bottom: 4px;
      margin-top: 10px;
    }

    .mini-cartela-card {
      min-width: 162px;
      background: #f8fafc;
      color: #0f172a;
      border-radius: 14px;
      overflow: hidden;
      border: 2px solid var(--accent-gold);
      flex-shrink: 0;
    }

    .mini-cartela-top {
      background: repeating-linear-gradient(
        135deg,
        var(--accent-gold) 0px,
        var(--accent-gold) 8px,
        #0f766e 8px,
        #0f766e 16px
      );
      height: 7px;
    }

    .mini-cartela-hdr {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 5px 9px 3px;
      font-size: 0.74rem;
      font-weight: 900;
    }

    .mini-5x5 {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 3px;
      padding: 4px 8px 8px;
    }

    .mini-cell {
      aspect-ratio: 1 / 1;
      border-radius: 50%;
      background: #e2e8f0;
      color: #0f172a;
      font-size: 0.6rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .mini-cell.star,
    .mini-cell.hit {
      background: #0f766e;
      color: #fff;
    }

    .btn-gold {
      width: 100%;
      background: var(--accent-gold);
      color: #111827;
      font-weight: 900;
      font-size: 1rem;
      border: none;
      border-radius: 14px;
      padding: 13px 16px;
      cursor: pointer;
    }

    .btn-gold:hover { background: var(--accent-gold-hover); }

    .btn-secondary-dark {
      background: var(--bg-card-soft);
      color: var(--text-main);
      border: 1px solid var(--line-border);
      border-radius: 12px;
      font-weight: 700;
      cursor: pointer;
    }

    .lobby-actions-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 8px;
    }

    .caller-card {
      background: var(--bg-card);
      border: 1px solid var(--line-border);
      border-radius: 22px;
      padding: 16px 14px;
      margin-bottom: 12px;
      text-align: center;
    }

    .featured-ball-ring {
      width: 144px;
      height: 144px;
      border-radius: 50%;
      margin: 0 auto 14px;
      padding: 10px;
      background: conic-gradient(
        #ef4444 0deg 60deg,
        #f59e0b 60deg 120deg,
        #22c55e 120deg 180deg,
        #06b6d4 180deg 240deg,
        #3b82f6 240deg 300deg,
        #a855f7 300deg 360deg
      );
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .featured-ball-core {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      background: #0c272c;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      line-height: 1;
    }

    .featured-ball-letter {
      font-size: 1rem;
      font-weight: 900;
      color: #c084fc;
      margin-bottom: 2px;
    }

    .featured-ball-num {
      font-size: 2.8rem;
      font-weight: 900;
      color: #ffffff;
    }

    .recent-balls-row {
      display: flex;
      gap: 8px;
      justify-content: center;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }

    .recent-ball {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #0d2b31;
      border: 2px solid var(--accent-gold);
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      font-size: 0.86rem;
    }

    .stats-strip-3 {
      display: grid;
      grid-template-columns: repeat(3, 1fr) auto;
      gap: 8px;
      align-items: center;
      border-top: 1px solid var(--line-border);
      padding-top: 10px;
      font-size: 0.84rem;
    }

    .stats-strip-3 strong {
      display: block;
      font-size: 1.02rem;
      font-weight: 900;
      color: #fff;
    }

    .stats-strip-3 span.lbl {
      font-size: 0.72rem;
      color: var(--text-muted);
    }

    .light-cartela-box {
      background: #f8fafc;
      color: #0f172a;
      border-radius: 18px;
      overflow: hidden;
      margin-bottom: 12px;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.28);
    }

    .light-cartela-hdr {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.86rem;
      font-weight: 900;
      padding: 8px 12px;
    }

    .bingo-5col {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 6px;
      padding: 0 10px 10px;
    }

    .bingo-head {
      text-align: center;
      font-weight: 900;
      font-size: 0.82rem;
      color: #fff;
      border-radius: 8px;
      padding: 5px 0;
    }

    .bingo-cell {
      aspect-ratio: 1 / 1;
      border-radius: 50%;
      background: #e2e8f0;
      color: #0f172a;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 0.9rem;
    }

    .bingo-cell.free,
    .bingo-cell.marked {
      background: #0f766e;
      color: #ffffff;
      font-weight: 900;
    }

    .bingo-cell.win-highlight {
      background: var(--accent-gold);
      color: #111827;
      border: 2px solid #0f172a;
      font-weight: 900;
    }

    .master-rows-wrap {
      background: var(--bg-card);
      border: 1px solid var(--line-border);
      border-radius: 16px;
      padding: 10px;
      margin-bottom: 12px;
    }

    .master-letter-row {
      display: grid;
      grid-template-columns: 24px repeat(15, 1fr);
      gap: 3px;
      align-items: center;
      margin-bottom: 4px;
    }

    .master-letter-row:last-child { margin-bottom: 0; }

    .master-row-tag {
      height: 20px;
      border-radius: 5px;
      font-size: 0.72rem;
      font-weight: 900;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .master-num-pill {
      height: 20px;
      border-radius: 5px;
      background: rgba(255, 255, 255, 0.08);
      color: var(--text-muted);
      font-size: 0.64rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .master-num-pill.called {
      background: var(--accent-gold);
      color: #111827;
      font-weight: 900;
    }

    .lobby-hero-card {
      background: var(--bg-card);
      border: 1px solid var(--line-border);
      border-radius: 18px;
      padding: 14px;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .countdown-ring {
      width: 74px;
      height: 74px;
      border-radius: 50%;
      background: conic-gradient(var(--accent-gold) 0deg, rgba(255, 255, 255, 0.14) 0deg);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      padding: 6px;
    }

    .countdown-ring-inner {
      width: 100%;
      height: 100%;
      border-radius: 50%;
      background: var(--bg-card);
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
      font-size: 1.12rem;
      color: #fff;
    }

    .wallet-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-bottom: 10px;
    }

    .wallet-action-btn {
      padding: 12px;
      border-radius: 14px;
      font-weight: 800;
      border: none;
      cursor: pointer;
    }

    .wallet-action-btn.deposit { background: var(--accent-green); color: #062814; }
    .wallet-action-btn.withdraw { background: var(--bg-card-soft); color: #fff; border: 1px solid var(--line-border); }

    .profile-badge-circle {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--accent-gold);
      color: #111827;
      font-weight: 900;
      font-size: 1.6rem;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
    }

    .modal-backdrop-custom {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.78);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      z-index: 100;
    }

    .modal-box-custom {
      width: 100%;
      max-width: 360px;
      background: var(--bg-card);
      border: 1px solid rgba(245, 183, 0, 0.55);
      border-radius: 20px;
      padding: 18px;
      text-align: center;
    }

    .winner-top-name {
      font-size: 1.6rem;
      font-weight: 900;
      color: #ffffff;
      margin-top: 2px;
    }

    .winning-board-card {
      background: #f8fafc;
      color: #0f172a;
      border-radius: 16px;
      overflow: hidden;
      margin: 12px 0 14px;
      border: 2px solid var(--accent-gold);
    }

    .bottom-nav {
      position: fixed;
      bottom: 10px;
      left: 12px;
      right: 12px;
      height: 64px;
      background: #143d45;
      border: 1px solid rgba(255, 255, 255, 0.14);
      border-radius: 24px;
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      padding: 5px;
      gap: 4px;
      z-index: 50;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.42);
    }

    .nav-tab {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 0.84rem;
      color: var(--text-muted);
      border-radius: 18px;
      cursor: pointer;
    }

    .nav-tab .nav-gem { font-size: 0.55rem; opacity: 0; margin-top: 2px; }
    .nav-tab.active {
      background: var(--accent-gold);
      color: #111827;
      transform: translateY(-3px);
    }
    .nav-tab.active .nav-gem { opacity: 1; }
  </style>
</head>
<body>
  <header class="top-bar">
    <div class="brand-pill">
      <span class="brand-diamond"></span>
      <span>Smart Bingo</span>
    </div>
    <div class="balance-box" onclick="switchTab('wallet')">
      <span class="balance-label">Balance</span>
      <button type="button" class="balance-pill" id="balance-display">0.00 ETB</button>
    </div>
  </header>
  <div class="top-stripe"></div>

  <!-- SCREEN 1: CHOOSE TABLE & CARTELAS -->
  <section id="screen-game" class="container">
    <h1 class="section-title">Choose a table</h1>
    <p class="section-sub">
      Share with friends, deposit ETB, and pick up to 3 cartelas. 15s countdown starts once cartelas are held. First closed line wins 80% (20% admin fee); 2 co-winners split 80% equally.
    </p>

    <div class="onboarding-strip">
      <div class="onboarding-step-text">1. Share invite &bull; 2. Deposit ETB &bull; 3. Pick cartela</div>
      <div class="onboarding-btns">
        <button type="button" class="btn-secondary-dark" style="padding:7px 11px;font-size:0.78rem;" onclick="shareInviteLink()">Share</button>
        <button type="button" class="btn-gold" style="width:auto;padding:7px 12px;font-size:0.78rem;" onclick="startInstantDeposit()">+ Deposit</button>
      </div>
    </div>

    <label class="table-card selected" style="border-left:6px solid #22c55e;" onclick="selectTable(10, this)">
      <input type="radio" name="stake" value="10" checked />
      <div class="table-big-amt">10</div>
      <div style="flex:1;">
        <div class="table-meta-title">ETB per cartela</div>
        <div class="table-meta-sub">1 line to win. Winner takes 80% of all stakes</div>
      </div>
      <span class="radio-ring"></span>
    </label>

    <label class="table-card" style="border-left:6px solid #38bdf8;" onclick="selectTable(20, this)">
      <input type="radio" name="stake" value="20" />
      <div class="table-big-amt">20</div>
      <div style="flex:1;">
        <div class="table-meta-title">ETB per cartela</div>
        <div class="table-meta-sub">1 line to win. Winner takes 80% of all stakes</div>
      </div>
      <span class="radio-ring"></span>
    </label>

    <label class="table-card" style="border-left:6px solid #ef4444;" onclick="selectTable(50, this)">
      <input type="radio" name="stake" value="50" />
      <div class="table-big-amt">50</div>
      <div style="flex:1;">
        <div class="table-meta-title">ETB per cartela</div>
        <div class="table-meta-sub">1 line to win. Winner takes 80% of all stakes</div>
      </div>
      <span class="radio-ring"></span>
    </label>

    <div class="card-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <div style="font-weight:800;font-size:0.9rem;">Stake <span id="active-stake-label">10</span> ETB per cartela</div>
        <div style="font-size:0.8rem;color:var(--accent-gold);font-weight:800;" id="setup-prize-pool">Prize: 0.00 ETB</div>
      </div>
      <div class="legend-row">
        <span><i class="legend-dot yours"></i>Yours</span>
        <span><i class="legend-dot taken"></i>Taken</span>
      </div>
      <div class="cartela-picker-scroll">
        <div id="cartela-grid-picker" class="cartela-grid"></div>
      </div>
      <div class="section-sub" id="selected-cartela-info" style="margin:10px 0 4px;">
        Your cartelas (0/3). Tap a number again to remove it.
      </div>
      <div id="selected-cartelas-preview" class="mini-preview-strip"></div>
    </div>

    <button type="button" id="play-btn" class="btn-gold" onclick="joinSelectedTable()">Select at least 1 Cartela</button>
  </section>

  <!-- SCREEN 2: LOBBY WAITING (ACTIVE GAME VISITOR) -->
  <section id="screen-wait-active" class="container d-none">
    <h1 class="section-title">Next Round Queue</h1>
    <p class="section-sub" id="wait-active-banner">Please wait until this game ends; you will start in the next round.</p>

    <div class="caller-card">
      <div class="featured-ball-ring">
        <div class="featured-ball-core">
          <span class="featured-ball-letter" id="wait-featured-letter">--</span>
          <span class="featured-ball-num" id="wait-featured-num">--</span>
        </div>
      </div>
      <div class="recent-balls-row" id="wait-recent-balls"></div>
      <div class="stats-strip-3">
        <div><strong id="wait-derash">0.00</strong><span class="lbl">ETB prize</span></div>
        <div><strong id="wait-calls">0/75</strong><span class="lbl">called</span></div>
        <div><strong id="wait-game-code">--</strong><span class="lbl">game</span></div>
        <button type="button" class="btn-secondary-dark" style="padding:6px 10px;font-size:0.75rem;" onclick="leaveToSetup()">Tables</button>
      </div>
    </div>

    <div id="wait-master-grid" class="master-rows-wrap"></div>
  </section>

  <!-- SCREEN 3: LOBBY 15s COUNTDOWN -->
  <section id="screen-lobby" class="container d-none">
    <h1 class="section-title">Starting Soon</h1>
    <div class="lobby-hero-card" style="margin-top:10px;">
      <div class="countdown-ring" id="lobby-countdown-ring">
        <div class="countdown-ring-inner" id="lobby-timer">15s</div>
      </div>
      <div style="flex:1;">
        <div style="font-size:0.76rem;color:var(--text-muted);font-weight:700;">Winner prize (80% pool)</div>
        <div style="font-size:1.55rem;font-weight:900;color:var(--accent-gold);line-height:1.1;" id="lobby-prize-pool">0.00 ETB</div>
        <div style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;" id="lobby-player-count">Players Joined: 1</div>
      </div>
    </div>
    <div class="card-panel">
      <div style="font-weight:800;font-size:0.88rem;margin-bottom:6px;">Your Reserved Cartelas</div>
      <div id="lobby-cartelas-preview" class="mini-preview-strip"></div>
    </div>
    <div class="lobby-actions-row">
      <button type="button" class="btn-gold" style="padding:11px;" onclick="playAgainQuick()">Play Again</button>
      <button type="button" class="btn-secondary-dark" style="padding:11px;" onclick="leaveToSetup()">Leave Lobby</button>
    </div>
  </section>

  <!-- SCREEN 4: ACTIVE PLAYROOM -->
  <section id="screen-playroom" class="container d-none">
    <div class="caller-card" style="margin-top:12px;">
      <div class="featured-ball-ring">
        <div class="featured-ball-core">
          <span class="featured-ball-letter" id="play-featured-letter">--</span>
          <span class="featured-ball-num" id="play-featured-num">--</span>
        </div>
      </div>

      <div class="recent-balls-row" id="play-recent-balls"></div>

      <div class="stats-strip-3">
        <div><strong id="play-derash">0</strong><span class="lbl">ETB prize</span></div>
        <div><strong id="play-calls">0/75</strong><span class="lbl">called</span></div>
        <div><strong id="play-cartelas-count">1</strong><span class="lbl">cartelas</span></div>
        <button id="sound-toggle" class="btn-secondary-dark" style="width:38px;height:38px;border-radius:50%;font-size:0.9rem;" onclick="toggleSound()">🔊</button>
      </div>
    </div>

    <div class="light-cartela-box">
      <div class="top-stripe"></div>
      <div class="light-cartela-hdr">
        <span>Smart Bingo</span>
        <span id="active-cartela-badge">#1</span>
      </div>
      <div class="bingo-5col" style="padding-bottom:6px;">
        <div class="bingo-head" style="background:#f59e0b;">B</div>
        <div class="bingo-head" style="background:#ef4444;">I</div>
        <div class="bingo-head" style="background:#06b6d4;">N</div>
        <div class="bingo-head" style="background:#22c55e;">G</div>
        <div class="bingo-head" style="background:#a855f7;">O</div>
      </div>
      <div id="board" class="bingo-5col"></div>
    </div>

    <div id="play-master-grid" class="master-rows-wrap"></div>

    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;">
      <label style="display:flex;align-items:center;gap:6px;font-size:0.82rem;cursor:pointer;">
        <input type="checkbox" id="auto-checkbox" checked onchange="toggleAutoMode()">
        <span>Auto <strong id="auto-status-label" style="color:#4ade80;">ON</strong></span>
      </label>
      <span style="font-size:0.78rem;color:var(--text-muted);">Game <strong id="play-game-code">--</strong></span>
      <button class="btn-secondary-dark" style="padding:6px 12px;font-size:0.78rem;" onclick="leaveToSetup()">Exit</button>
    </div>
  </section>

  <!-- SCREEN 5: HISTORY -->
  <section id="screen-history" class="container d-none">
    <h1 class="section-title">Game History</h1>
    <div class="card-panel" id="history-list">
      <p class="section-sub" style="margin:0;text-align:center;">No recent games recorded yet.</p>
    </div>
  </section>

  <!-- SCREEN 6: WALLET -->
  <section id="screen-wallet" class="container d-none">
    <h1 class="section-title">Wallet</h1>
    <div class="card-panel">
      <div style="font-size:2rem;font-weight:900;" id="wallet-withdrawable">0.00 ETB</div>
      <div style="color:var(--accent-green);font-weight:700;font-size:0.88rem;margin-bottom:10px;">Can be withdrawn</div>
      <div style="display:flex;justify-content:space-between;border-top:1px solid var(--line-border);padding-top:10px;font-size:0.9rem;">
        <span style="color:var(--text-muted);">Play credits</span>
        <strong id="wallet-balance">0.00 ETB</strong>
      </div>
    </div>

    <div class="wallet-grid-2">
      <button class="wallet-action-btn deposit" onclick="startInstantDeposit()">Deposit ↓</button>
      <button class="wallet-action-btn withdraw" onclick="requestWithdrawal()">Withdraw ↑</button>
    </div>
  </section>

  <!-- SCREEN 7: PROFILE -->
  <section id="screen-profile" class="container d-none">
    <h1 class="section-title">Profile</h1>
    <div class="card-panel" style="text-align:center;">
      <div class="profile-badge-circle" id="profile-avatar">P</div>
      <div style="font-size:1.2rem;font-weight:800;" id="profile-name">Player</div>
      <div style="color:var(--accent-green);font-size:0.85rem;margin-top:2px;">Verified player</div>
      <div style="color:var(--text-muted);font-size:0.78rem;margin-top:4px;">ID: <span id="profile-id">--</span></div>
      <div style="margin-top:12px;">
        <button type="button" class="btn-gold" style="padding:9px 12px;font-size:0.86rem;" onclick="shareInviteLink()">
          Copy Invite Link
        </button>
      </div>
    </div>
  </section>

  <!-- WINNER MODAL WITH PLAY AGAIN + LEAVE LOBBY -->
  <div id="winner-modal" class="modal-backdrop-custom d-none">
    <div class="modal-box-custom">
      <div style="color:var(--text-muted);font-weight:800;font-size:0.95rem;">Bingo</div>
      <div id="winner-top-name" class="winner-top-name">Winner</div>
      <div id="winner-list-banner" style="color:var(--accent-gold);font-weight:900;font-size:1.15rem;margin-top:2px;">0 ETB</div>

      <div class="winning-board-card">
        <div class="top-stripe"></div>
        <div class="light-cartela-hdr">
          <span>Winning cartela</span>
          <span id="winner-board-title">#--</span>
        </div>
        <div class="bingo-5col" style="padding-bottom:6px;">
          <div class="bingo-head" style="background:#f59e0b;">B</div>
          <div class="bingo-head" style="background:#ef4444;">I</div>
          <div class="bingo-head" style="background:#06b6d4;">N</div>
          <div class="bingo-head" style="background:#22c55e;">G</div>
          <div class="bingo-head" style="background:#a855f7;">O</div>
        </div>
        <div id="winner-board-grid" class="bingo-5col"></div>
      </div>

      <div class="lobby-actions-row">
        <button type="button" class="btn-gold" style="padding:11px;" onclick="playAgainFromModal()">Play Again</button>
        <button type="button" class="btn-secondary-dark" style="padding:11px;" onclick="leaveLobbyFromModal()">Leave Lobby</button>
      </div>
    </div>
  </div>

  <!-- BOTTOM NAVIGATION -->
  <nav class="bottom-nav">
    <div class="nav-tab active" onclick="switchTab('game')" id="nav-game">
      <span>Game</span>
      <span class="nav-gem">◆</span>
    </div>
    <div class="nav-tab" onclick="switchTab('history')" id="nav-history">
      <span>History</span>
      <span class="nav-gem">◆</span>
    </div>
    <div class="nav-tab" onclick="switchTab('wallet')" id="nav-wallet">
      <span>Wallet</span>
      <span class="nav-gem">◆</span>
    </div>
    <div class="nav-tab" onclick="switchTab('profile')" id="nav-profile">
      <span>Profile</span>
      <span class="nav-gem">◆</span>
    </div>
  </nav>

  <script>
    const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    if (tg) { tg.ready(); tg.expand(); }

    function getUserId() {
      if (tg && tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.id) {
        const id = tg.initDataUnsafe.user.id.toString();
        localStorage.setItem('Smart_bingo_tg_id', id);
        return id;
      }
      const p = new URLSearchParams(window.location.search);
      if (p.has('telegram_id')) {
        const id = p.get('telegram_id');
        localStorage.setItem('Smart_bingo_tg_id', id);
        return id;
      }
      return localStorage.getItem('Smart_bingo_tg_id') || '730967613';
    }

    function getReferrerId() {
      const p = new URLSearchParams(window.location.search);
      const raw = p.get('start') || p.get('ref') || (tg && tg.initDataUnsafe && tg.initDataUnsafe.start_param) || '';
      const cleaned = raw.replace(/^ref[_-]?/i, '').replace(/\D+/g, '');
      if (cleaned) { localStorage.setItem('Smart_bingo_ref_id', cleaned); return cleaned; }
      return localStorage.getItem('Smart_bingo_ref_id') || '';
    }

    function resolveDisplayName() {
      const u = tg && tg.initDataUnsafe ? tg.initDataUnsafe.user : null;
      if (u) {
        const full = [u.first_name, u.last_name].filter(Boolean).join(' ').trim();
        if (full) { localStorage.setItem('Smart_bingo_player_name', full); return full; }
        if (u.username) { localStorage.setItem('Smart_bingo_player_name', u.username); return u.username; }
      }
      const p = new URLSearchParams(window.location.search);
      const qName = (p.get('first_name') || p.get('name') || '').trim();
      if (qName) { localStorage.setItem('Smart_bingo_player_name', qName); return qName; }
      return localStorage.getItem('Smart_bingo_player_name') || 'Player';
    }

    const telegramId = getUserId();
    const referrerId = getReferrerId();
    const user = tg && tg.initDataUnsafe ? tg.initDataUnsafe.user : null;
    const playerName = resolveDisplayName();

    document.getElementById('profile-name').textContent = playerName;
    document.getElementById('profile-avatar').textContent = (playerName.charAt(0) || 'P').toUpperCase();
    document.getElementById('profile-id').textContent = telegramId || 'Guest';

    let balance = 0.00;
    let selectedStake = 10;
    let selectedCartelaNumbers = [];
    let lastJoinedCartelas = [];
    let takenCartelas = [];
    let currentRoomId = null;
    let currentGameCode = '--';
    let currentDerash = 0;
    let activeCartelaNumber = 1;
    let card = [];
    let marked = Array(25).fill(false);
    let calledNumbers = [];
    let lastAnnouncedNumber = null;
    let autoMode = true;
    let winnerModalShownForRoom = null;
    let roomPollInterval = null;
    let gameHistory = [];
    let soundEnabled = true;
    let audioCtx = null;

    function getBallLetter(n) {
      if (n <= 15) return 'B';
      if (n <= 30) return 'I';
      if (n <= 45) return 'N';
      if (n <= 60) return 'G';
      return 'O';
    }

    function generateDeterministicCard(cartelaNumber) {
      let seed = (cartelaNumber * 2654435761) & 0x7fffffff;
      if (seed === 0) seed = 1;
      function randInt(maxExclusive) {
        seed = (seed * 1103515245 + 12345) & 0x7fffffff;
        return seed % maxExclusive;
      }
      const ranges = [[1, 15], [16, 30], [31, 45], [46, 60], [61, 75]];
      const cols = ranges.map(([min, max]) => {
        const pool = [];
        for (let i = min; i <= max; i++) pool.push(i);
        for (let i = pool.length - 1; i > 0; i--) {
          const j = randInt(i + 1);
          [pool[i], pool[j]] = [pool[j], pool[i]];
        }
        return pool.slice(0, 5);
      });
      const out = [];
      for (let r = 0; r < 5; r++) {
        for (let c = 0; c < 5; c++) {
          out.push((r === 2 && c === 2) ? 0 : cols[c][r]);
        }
      }
      return out;
    }

    function hasWinningLineClient(boardArr, calledSet) {
      if (!Array.isArray(boardArr) || boardArr.length !== 25) return null;
      const lines = [
        [0,1,2,3,4], [5,6,7,8,9], [10,11,12,13,14], [15,16,17,18,19], [20,21,22,23,24],
        [0,5,10,15,20], [1,6,11,16,21], [2,7,12,17,22], [3,8,13,18,23], [4,9,14,19,24],
        [0,6,12,18,24], [4,8,12,16,20],
      ];
      for (const line of lines) {
        let ok = true;
        for (const idx of line) {
          if (idx === 12) continue;
          const val = Number(boardArr[idx]);
          if (!calledSet.has(val)) {
            ok = false;
            break;
          }
        }
        if (ok) return line;
      }
      return null;
    }

    function updateCountdownRing(ringId, secs, maxSecs = 15) {
      const ring = document.getElementById(ringId);
      if (!ring) return;
      const clamped = Math.max(0, Math.min(maxSecs, Number(secs) || 0));
      const deg = Math.round((clamped / maxSecs) * 360);
      ring.style.background = `conic-gradient(var(--accent-gold) ${deg}deg, rgba(255, 255, 255, 0.14) ${deg}deg 360deg)`;
    }

    function renderMiniCartelaPreviews(containerId, numbers) {
      const box = document.getElementById(containerId);
      if (!box) return;
      if (!numbers || numbers.length === 0) { box.innerHTML = ''; return; }
      const calledSet = new Set(calledNumbers);
      box.innerHTML = numbers.map(num => {
        const board = generateDeterministicCard(num);
        const cellsHtml = board.map((val, idx) => {
          const isStar = idx === 12;
          const isHit = !isStar && calledSet.has(val);
          return `<div class="mini-cell${isStar ? ' star' : (isHit ? ' hit' : '')}">${isStar ? '★' : val}</div>`;
        }).join('');
        return `
          <div class="mini-cartela-card">
            <div class="mini-cartela-top"></div>
            <div class="mini-cartela-hdr">
              <span>Smart Bingo</span>
              <span>#${num}</span>
            </div>
            <div class="mini-5x5">${cellsHtml}</div>
          </div>
        `;
      }).join('');
    }

    function updatePlayButtonLabel() {
      const btn = document.getElementById('play-btn');
      btn.textContent = selectedCartelaNumbers.length > 0
        ? `Play for ${selectedStake * selectedCartelaNumbers.length} ETB`
        : 'Select at least 1 Cartela';
      renderMiniCartelaPreviews('selected-cartelas-preview', selectedCartelaNumbers);
    }

    function buildCartelaPickerGrid() {
      const gridContainer = document.getElementById('cartela-grid-picker');
      gridContainer.innerHTML = '';
      for (let i = 1; i <= 400; i++) {
        const isTaken = takenCartelas.includes(i);
        const isSelected = selectedCartelaNumbers.includes(i);
        const circle = document.createElement('div');
        circle.className = 'cartela-circle' + (isTaken ? ' taken' : '') + (isSelected ? ' selected' : '');
        circle.textContent = i;
        if (!isTaken) circle.onclick = () => toggleCartelaNumber(i);
        gridContainer.appendChild(circle);
      }
      document.getElementById('selected-cartela-info').textContent =
        `Your cartelas (${selectedCartelaNumbers.length}/3). Tap a number again to remove it.`;
      updatePlayButtonLabel();
    }

    function toggleCartelaNumber(num) {
      if (takenCartelas.includes(num)) return;
      const idx = selectedCartelaNumbers.indexOf(num);
      if (idx > -1) {
        selectedCartelaNumbers.splice(idx, 1);
      } else if (selectedCartelaNumbers.length < 3) {
        selectedCartelaNumbers.push(num);
      } else {
        alert('You can select a maximum of 3 cartelas per round.');
        return;
      }
      buildCartelaPickerGrid();
    }

    function selectTable(stake, element) {
      document.querySelectorAll('.table-card').forEach(el => {
        el.classList.remove('selected');
        const radio = el.querySelector('input[type="radio"]');
        if (radio) radio.checked = false;
      });
      element.classList.add('selected');
      const activeRadio = element.querySelector('input[type="radio"]');
      if (activeRadio) activeRadio.checked = true;
      selectedStake = stake;
      document.getElementById('active-stake-label').textContent = stake;
      updatePlayButtonLabel();
      pollSetupRoomStatus();
    }

    function stopRoomPolling() {
      if (roomPollInterval) { clearInterval(roomPollInterval); roomPollInterval = null; }
    }

    function showOnlyScreen(screenName) {
      ['game', 'wait-active', 'lobby', 'playroom', 'history', 'wallet', 'profile'].forEach(s => {
        const el = document.getElementById(`screen-${s}`);
        if (el) el.classList.add('d-none');
      });
      const target = document.getElementById(`screen-${screenName}`);
      if (target) target.classList.remove('d-none');
    }

    function leaveToSetup() {
      stopRoomPolling();
      currentRoomId = null;
      if ('speechSynthesis' in window) window.speechSynthesis.cancel();
      switchTab('game');
      pollSetupRoomStatus();
    }

    function playAgainQuick() {
      stopRoomPolling();
      currentRoomId = null;
      if (lastJoinedCartelas.length > 0) {
        selectedCartelaNumbers = lastJoinedCartelas.filter(n => !takenCartelas.includes(n)).slice(0, 3);
      }
      if (selectedCartelaNumbers.length === 0) {
        for (let n = 1; n <= 400; n++) {
          if (!takenCartelas.includes(n)) {
            selectedCartelaNumbers = [n];
            break;
          }
        }
      }
      showOnlyScreen('game');
      buildCartelaPickerGrid();
      joinSelectedTable();
    }

    function switchTab(tab) {
      document.querySelectorAll('.bottom-nav .nav-tab').forEach(n => n.classList.remove('active'));
      const tabEl = document.getElementById(`nav-${tab}`);
      if (tabEl) tabEl.classList.add('active');
      showOnlyScreen(tab);
      if (tab === 'game') { buildCartelaPickerGrid(); pollSetupRoomStatus(); }
      else if (tab === 'history') { renderHistory(); }
      syncBalance();
    }

    function renderHistory() {
      const box = document.getElementById('history-list');
      if (gameHistory.length === 0) {
        box.innerHTML = `<p class="section-sub" style="margin:0;text-align:center;">No recent games recorded yet.</p>`;
        return;
      }
      box.innerHTML = gameHistory.map(item => `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--line-border);font-size:0.84rem;">
          <div><strong>Game ${item.code}</strong> <span style="color:var(--text-muted);margin-left:6px;">Cartela #${item.board}</span></div>
          <strong style="color:${item.won ? '#4ade80' : 'var(--accent-gold)'};">${item.status}</strong>
        </div>
      `).join('');
    }

    function initAudio() {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
    }

    function toggleSound() {
      soundEnabled = !soundEnabled;
      const btn = document.getElementById('sound-toggle');
      if (btn) btn.textContent = soundEnabled ? '🔊' : '🔇';
    }

    function playTone(freq, type, duration, delay = 0) {
      if (!soundEnabled) return;
      initAudio();
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = type;
      osc.frequency.setValueAtTime(freq, audioCtx.currentTime + delay);
      gain.gain.setValueAtTime(0.15, audioCtx.currentTime + delay);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + delay + duration);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(audioCtx.currentTime + delay);
      osc.stop(audioCtx.currentTime + delay + duration);
    }

    const sounds = {
      ballPop: () => { playTone(520, 'sine', 0.12, 0); playTone(780, 'sine', 0.15, 0.06); },
      daubMatch: () => { playTone(659.25, 'triangle', 0.12, 0); playTone(880, 'triangle', 0.25, 0.08); },
      winFanfare: () => { [523.25, 659.25, 783.99, 1046.50].forEach((n, i) => playTone(n, 'triangle', 0.35, i * 0.12)); },
      errorBuzz: () => { playTone(180, 'sawtooth', 0.2, 0); playTone(140, 'sawtooth', 0.25, 0.15); }
    };

    function announceBall(letter, number) {
      if (!soundEnabled || !('speechSynthesis' in window)) return;
      window.speechSynthesis.cancel();
      const ut = new SpeechSynthesisUtterance(`${letter} ${number}`);
      ut.rate = 0.95;
      ut.pitch = 1.05;
      window.speechSynthesis.speak(ut);
    }

    function updateBalanceUI(newBal) {
      balance = parseFloat(newBal) || 0.00;
      const formatted = `${balance.toFixed(2)} ETB`;
      document.getElementById('balance-display').textContent = formatted;
      document.getElementById('wallet-balance').textContent = formatted;
      document.getElementById('wallet-withdrawable').textContent = formatted;
    }

    function syncBalance() {
      if (!telegramId) return;
      fetch(`api.php?action=get_balance&telegram_id=${encodeURIComponent(telegramId)}`)
        .then(r => r.json())
        .then(d => { if (d.success && d.balance !== undefined) updateBalanceUI(d.balance); })
        .catch(() => {});
    }

    function renderHorizontalMasterBoard(containerId, calledSet, latestNum) {
      const container = document.getElementById(containerId);
      if (!container) return;
      const rowsMeta = [
        { letter: 'B', start: 1,  color: '#f59e0b' },
        { letter: 'I', start: 16, color: '#ef4444' },
        { letter: 'N', start: 31, color: '#06b6d4' },
        { letter: 'G', start: 46, color: '#22c55e' },
        { letter: 'O', start: 61, color: '#a855f7' },
      ];
      container.innerHTML = rowsMeta.map(row => {
        let pills = '';
        for (let offset = 0; offset < 15; offset++) {
          const n = row.start + offset;
          const isCalled = calledSet.has(n);
          const cls = 'master-num-pill' + (isCalled ? ' called' : '');
          pills += `<div class="${cls}">${n}</div>`;
        }
        return `<div class="master-letter-row"><div class="master-row-tag" style="background:${row.color};">${row.letter}</div>${pills}</div>`;
      }).join('');
    }

    function renderRecentBalls(containerId, calledArr) {
      const box = document.getElementById(containerId);
      if (!box) return;
      const recent = calledArr.slice(-6).reverse();
      box.innerHTML = '';
      for (let i = 0; i < 6; i++) {
        const n = recent[i];
        const el = document.createElement('div');
        el.className = 'recent-ball';
        el.textContent = n !== undefined ? `${n}` : '--';
        box.appendChild(el);
      }
    }

    function renderPlayerCard(winLine = null) {
      const boardEl = document.getElementById('board');
      boardEl.innerHTML = '';
      const winSet = new Set(Array.isArray(winLine) ? winLine : []);
      card.forEach((num, idx) => {
        const cell = document.createElement('div');
        const isFree = idx === 12;
        const isMarked = isFree || marked[idx];
        const isWinCell = winSet.has(idx);
        cell.className = 'bingo-cell' +
          (isWinCell ? ' win-highlight' : (isFree ? ' free' : (isMarked ? ' marked' : '')));
        cell.textContent = isFree ? '★' : num;
        boardEl.appendChild(cell);
      });
    }

    function toggleAutoMode() {
      const cb = document.getElementById('auto-checkbox');
      autoMode = cb ? cb.checked : !autoMode;
      const label = document.getElementById('auto-status-label');
      label.textContent = autoMode ? 'ON' : 'OFF';
      label.style.color = autoMode ? '#4ade80' : '#ff8c00';
    }

    function openWinnerModal(roomId, winners, roomObj) {
      const key = `${roomId}:${(roomObj && roomObj.game_code) || ''}`;
      if (!roomId || winnerModalShownForRoom === key) return;
      winnerModalShownForRoom = key;
      stopRoomPolling();
      currentRoomId = null;
      syncBalance();
      if ('speechSynthesis' in window) window.speechSynthesis.cancel();
      sounds.winFanfare();
      if (tg && tg.HapticFeedback) tg.HapticFeedback.notificationOccurred('success');

      const topNameEl = document.getElementById('winner-top-name');
      const banner = document.getElementById('winner-list-banner');
      const didIWin = Array.isArray(winners) && winners.some(w => String(w.telegram_id) === String(telegramId));
      const myEntry = Array.isArray(winners) ? winners.find(w => String(w.telegram_id) === String(telegramId)) : null;

      if (!winners || winners.length === 0) {
        topNameEl.textContent = 'No Winner';
        banner.textContent = 'Round Ended';
      } else {
        const capped = winners.slice(0, 2);
        const names = capped.map(w => (w.name && w.name !== 'Player') ? w.name : playerName).join(' & ');
        const prizeAmt = Number(capped[0].prize || 0).toFixed(2);
        topNameEl.textContent = names;
        banner.textContent = capped.length > 1
          ? `${prizeAmt} ETB each (shared 80% pool)`
          : `${prizeAmt} ETB (80% pool)`;
      }

      const firstWinner = (winners && winners.length > 0) ? winners[0] : null;
      const winBoard = (firstWinner && Array.isArray(firstWinner.board) && firstWinner.board.length === 25)
        ? firstWinner.board
        : generateDeterministicCard(activeCartelaNumber);
      const winLineSet = new Set((firstWinner && Array.isArray(firstWinner.winning_line)) ? firstWinner.winning_line : []);
      const calledSet = new Set((roomObj && Array.isArray(roomObj.called_numbers)) ? roomObj.called_numbers : calledNumbers);

      document.getElementById('winner-board-title').textContent =
        firstWinner ? `#${firstWinner.cartela_number}` : `#${activeCartelaNumber}`;

      const grid = document.getElementById('winner-board-grid');
      grid.innerHTML = '';
      winBoard.forEach((num, idx) => {
        const cell = document.createElement('div');
        const isFree = idx === 12;
        const isCalled = isFree || calledSet.has(num);
        const isWinCell = winLineSet.has(idx);
        cell.className = 'bingo-cell' +
          (isWinCell ? ' win-highlight' : (isFree ? ' free' : (isCalled ? ' marked' : '')));
        cell.textContent = isFree ? '★' : num;
        grid.appendChild(cell);
      });

      gameHistory.unshift({
        code: currentGameCode,
        board: firstWinner ? firstWinner.cartela_number : activeCartelaNumber,
        won: didIWin,
        status: didIWin ? `Won +${Number((myEntry && myEntry.prize) || 0).toFixed(2)} ETB` : 'Finished'
      });

      document.getElementById('winner-modal').classList.remove('d-none');
    }

    function playAgainFromModal() {
      document.getElementById('winner-modal').classList.add('d-none');
      playAgainQuick();
    }

    function leaveLobbyFromModal() {
      document.getElementById('winner-modal').classList.add('d-none');
      selectedCartelaNumbers = [];
      leaveToSetup();
    }

    function renderWaitActiveScreen(room, customMsg = '') {
      showOnlyScreen('wait-active');
      document.getElementById('wait-active-banner').textContent =
        customMsg || 'Please wait until this game ends; you will start in the next round.';
      document.getElementById('wait-game-code').textContent = room.game_code || '--';
      document.getElementById('wait-derash').textContent = Number(room.derash || 0).toFixed(2);
      const arr = Array.isArray(room.called_numbers) ? room.called_numbers : [];
      const latest = arr.length > 0 ? arr[arr.length - 1] : null;
      document.getElementById('wait-calls').textContent = `${arr.length}/75`;
      document.getElementById('wait-featured-letter').textContent = latest !== null ? getBallLetter(latest) : '--';
      document.getElementById('wait-featured-num').textContent = latest !== null ? latest : '--';
      renderRecentBalls('wait-recent-balls', arr);
      renderHorizontalMasterBoard('wait-master-grid', new Set(arr), latest);
    }

    function renderPlayroomScreen(room) {
      showOnlyScreen('playroom');
      currentGameCode = room.game_code || '--';
      currentDerash = Number(room.derash || 0);
      const arr = Array.isArray(room.called_numbers) ? room.called_numbers : [];
      const latest = arr.length > 0 ? arr[arr.length - 1] : null;

      document.getElementById('play-game-code').textContent = currentGameCode;
      document.getElementById('play-derash').textContent = currentDerash.toFixed(0);
      document.getElementById('play-calls').textContent = `${arr.length}/75`;
      document.getElementById('play-cartelas-count').textContent = `${Math.max(1, selectedCartelaNumbers.length)}`;
      document.getElementById('active-cartela-badge').textContent = `#${activeCartelaNumber}`;
      document.getElementById('play-featured-letter').textContent = latest !== null ? getBallLetter(latest) : '--';
      document.getElementById('play-featured-num').textContent = latest !== null ? latest : '--';

      if (latest !== null && latest !== lastAnnouncedNumber) {
        lastAnnouncedNumber = latest;
        sounds.ballPop();
        announceBall(getBallLetter(latest), latest);
      }

      calledNumbers = arr;
      renderRecentBalls('play-recent-balls', arr);
      renderHorizontalMasterBoard('play-master-grid', new Set(arr), latest);

      const calledSet = new Set(calledNumbers);
      let changed = false;
      card.forEach((num, idx) => {
        if (idx !== 12 && calledSet.has(num) && !marked[idx]) {
          marked[idx] = true;
          changed = true;
        }
      });
      const winLine = hasWinningLineClient(card, calledSet);
      if (changed || winLine) {
        if (changed) sounds.daubMatch();
        renderPlayerCard(winLine);
      }
    }

    function startRoomPolling(roomId) {
      stopRoomPolling();
      currentRoomId = roomId;

      const tick = () => {
        if (!currentRoomId) return;
        fetch(`api.php?action=get_room_state&room_id=${encodeURIComponent(currentRoomId)}&stake=${encodeURIComponent(selectedStake)}`)
          .then(r => r.json())
          .then(data => {
            if (!data.success || !data.room) return;
            const room = data.room;
            if (room.status === 'finished') {
              stopRoomPolling();
              currentRoomId = null;
              openWinnerModal(room.room_id, room.winners || [], room);
              return;
            }
            if (room.status === 'waiting') {
              showOnlyScreen('lobby');
              const secs = Math.max(0, Number(room.lobby_seconds_left || 0));
              document.getElementById('lobby-timer').textContent = `${secs}s`;
              updateCountdownRing('lobby-countdown-ring', secs, 15);
              document.getElementById('lobby-prize-pool').textContent = `${Number(room.derash || 0).toFixed(2)} ETB`;
              document.getElementById('lobby-player-count').textContent = `Players Joined: ${room.players_count || 1}`;
              renderMiniCartelaPreviews('lobby-cartelas-preview', selectedCartelaNumbers);
            } else if (room.status === 'active') {
              if (card.length !== 25) {
                activeCartelaNumber = selectedCartelaNumbers[0] || 1;
                card = generateDeterministicCard(activeCartelaNumber);
                marked = Array(25).fill(false);
                marked[12] = true;
                lastAnnouncedNumber = null;
                renderPlayerCard();
              }
              renderPlayroomScreen(room);
            }
          })
          .catch(() => {});
      };

      tick();
      roomPollInterval = setInterval(tick, 1500);
    }

    function pollSetupRoomStatus() {
      fetch(`api.php?action=get_room_state&stake=${encodeURIComponent(selectedStake)}`)
        .then(r => r.json())
        .then(data => {
          if (!data.success) return;
          if (data.room && data.room.status === 'waiting') {
            document.getElementById('setup-prize-pool').textContent = `Prize: ${Number(data.room.derash || 0).toFixed(2)} ETB`;
            takenCartelas = Array.isArray(data.room.taken_cartelas) ? data.room.taken_cartelas : [];
            selectedCartelaNumbers = selectedCartelaNumbers.filter(n => !takenCartelas.includes(n));
            buildCartelaPickerGrid();
          } else {
            document.getElementById('setup-prize-pool').textContent = 'Prize: 0.00 ETB';
            takenCartelas = [];
            buildCartelaPickerGrid();
          }
        })
        .catch(() => {});
    }

    function startWaitForActiveGame(room, noticeMsg = '') {
      currentRoomId = room.room_id;
      renderWaitActiveScreen(room, noticeMsg || 'Please wait until this game ends; you will start in the next round.');
      stopRoomPolling();
      roomPollInterval = setInterval(() => {
        fetch(`api.php?action=get_room_state&room_id=${encodeURIComponent(room.room_id)}&stake=${encodeURIComponent(selectedStake)}`)
          .then(r => r.json())
          .then(data => {
            if (!data.success || !data.room) return;
            if (data.room.status === 'active') {
              renderWaitActiveScreen(data.room, noticeMsg || 'Please wait until this game ends; you will start in the next round.');
            } else if (data.room.status === 'finished' || data.room.status === 'waiting') {
              stopRoomPolling();
              currentRoomId = null;
              selectedCartelaNumbers = [];
              leaveToSetup();
            }
          })
          .catch(() => {});
      }, 1500);
    }

    function joinSelectedTable() {
      if (selectedCartelaNumbers.length === 0) {
        alert('Please select at least 1 cartela before playing.');
        return;
      }
      initAudio();

      const tgUsername = (user && user.username) ? user.username : '';
      const formData = new FormData();
      formData.append('action', 'join_room');
      formData.append('telegram_id', telegramId);
      formData.append('first_name', playerName);
      formData.append('username', tgUsername);
      formData.append('stake', selectedStake);
      formData.append('cartela_numbers', selectedCartelaNumbers.join(','));
      if (referrerId) formData.append('referrer_id', referrerId);

      fetch('api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          if (data.already_started && data.room) {
            sounds.errorBuzz();
            startWaitForActiveGame(data.room, data.error || 'Please wait until this game ends; you will start in the next round.');
            return;
          }
          if (!data.success) {
            sounds.errorBuzz();
            alert(data.error || 'Could not join table.');
            pollSetupRoomStatus();
            return;
          }

          lastJoinedCartelas = [...selectedCartelaNumbers];
          if (data.new_balance !== undefined) updateBalanceUI(data.new_balance);
          card = [];
          marked = Array(25).fill(false);
          marked[12] = true;
          winnerModalShownForRoom = null;
          startRoomPolling(data.room_id);
        })
        .catch(() => alert('Network error while joining table.'));
    }

    function extractTxRefFromMessage(rawText) {
      if (!rawText) return '';
      const trimmed = rawText.trim();
      const patterns = [
        /\b(?:transaction\s*(?:id|no|number)|tx\s*ref|ref(?:erence)?\s*(?:id|no)?|receipt\s*(?:no)?|ft)\s*[:#-]?\s*([A-Z0-9]{6,25})\b/i,
        /\b([A-Z]{2,6}[0-9]{4,}[A-Z0-9]{0,12})\b/,
        /\b([A-Z0-9]{8,20})\b/
      ];
      for (const re of patterns) {
        const m = trimmed.match(re);
        if (m && m[1]) return m[1].toUpperCase();
      }
      return trimmed.slice(0, 32).toUpperCase();
    }

    function extractAmountFromMessage(rawText) {
      if (!rawText) return null;
      const m = rawText.match(/(\d+(?:\.\d{1,2})?)\s*(?:ብር|ETB)\b/i);
      if (m && m[1]) {
        const val = parseFloat(m[1]);
        return (!isNaN(val) && val >= 10) ? val : null;
      }
      return null;
    }

    function startInstantDeposit() {
      const fullMsg = prompt('Paste the FULL SMS or payment message from Telebirr or CBE:', '');
      if (!fullMsg || !fullMsg.trim()) { alert('Please paste your payment message.'); return; }
      const cleanMessage = fullMsg.trim();
      const detected = extractAmountFromMessage(cleanMessage);
      const amountStr = prompt('Enter deposit amount in ETB (minimum 10):', detected !== null ? detected.toFixed(2) : '30');
      if (!amountStr) return;
      let amountNum = parseFloat(amountStr);
      if (isNaN(amountNum) || amountNum < 10) { alert('Minimum deposit is 10 ETB.'); return; }
      if (detected !== null && Math.abs(amountNum - detected) > 0.01) amountNum = detected;

      const formData = new FormData();
      formData.append('telegram_id', telegramId);
      formData.append('first_name', playerName);
      formData.append('amount', amountNum.toFixed(2));
      formData.append('tx_ref', extractTxRefFromMessage(cleanMessage));
      formData.append('full_message', cleanMessage);

      fetch('verify_payment.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          if (data.new_balance !== undefined) updateBalanceUI(data.new_balance);
          alert(data.message || data.error || 'Submitted.');
        })
        .catch(err => alert('Network error: ' + err.message));
    }

    function requestWithdrawal() {
      const amount = prompt(`Enter amount to withdraw (Available: ${balance.toFixed(2)} ETB):`, '50');
      if (!amount) return;
      const amtNum = parseFloat(amount);
      if (isNaN(amtNum) || amtNum <= 0 || amtNum > balance) { alert('Invalid amount or insufficient balance.'); return; }
      const method = prompt('Choose payout method (Telebirr or CBE):', 'Telebirr');
      if (!method) return;
      const account = prompt(`Enter your ${method} Phone/Account number:`, '');
      if (!account) return;

      const formData = new FormData();
      formData.append('action', 'request_withdrawal');
      formData.append('telegram_id', telegramId);
      formData.append('amount', amtNum);
      formData.append('method', method);
      formData.append('account', account);

      fetch('api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            updateBalanceUI(data.new_balance);
            alert(data.message || 'Withdrawal requested!');
          } else {
            alert(data.error || 'Withdrawal failed.');
          }
        })
        .catch(err => alert('Network error: ' + err.message));
    }

    function shareInviteLink() {
      const link = `https://t.me/SmartBingoHub_bot?start=ref_${encodeURIComponent(telegramId)}`;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(() => alert(`Invite link copied!\n\n${link}`)).catch(() => prompt('Copy link:', link));
      } else {
        prompt('Copy link:', link);
      }
    }

    setInterval(syncBalance, 5000);
    window.addEventListener('focus', syncBalance);

    buildCartelaPickerGrid();
    syncBalance();
    pollSetupRoomStatus();
  </script>
</body>
</html>
