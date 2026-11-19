<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>Future Bingo</title>
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <style>
    :root {
      --bg-app: #06282b;
      --bg-card: #0d3b3e;
      --bg-card-alt: #12494d;
      --accent-gold: #f5b41b;
      --accent-green: #8cc63f;
      --accent-cyan: #57b9c7;
      --accent-red: #e0533c;
      --text-main: #ffffff;
      --text-muted: #9bc0c2;
      --border-subtle: rgba(255, 255, 255, 0.12);
    }

    * {
      box-sizing: border-box;
      user-select: none;
      -webkit-tap-highlight-color: transparent;
    }

    body {
      margin: 0;
      padding: 0 0 88px 0;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: var(--bg-app);
      color: var(--text-main);
      min-height: 100vh;
    }

    .top-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 16px 10px;
      background: #083034;
      border-bottom: 3px solid var(--accent-gold);
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 800;
      font-size: 1.15rem;
    }

    .brand-diamond {
      width: 22px;
      height: 22px;
      background: var(--accent-gold);
      transform: rotate(45deg);
      border-radius: 4px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .brand-diamond::after {
      content: "";
      width: 8px;
      height: 8px;
      background: var(--accent-red);
      border-radius: 2px;
    }

    .balance-pill {
      text-align: right;
    }

    .balance-pill .lbl {
      font-size: 0.72rem;
      color: var(--text-muted);
      display: block;
    }

    .balance-pill .val {
      font-size: 1.05rem;
      font-weight: 800;
      color: var(--accent-gold);
    }

    .container {
      padding: 16px;
      max-width: 520px;
      margin: 0 auto;
    }

    .page-title {
      font-size: 1.65rem;
      font-weight: 800;
      margin: 4px 0 8px;
    }

    .page-subtitle {
      color: var(--text-muted);
      font-size: 0.92rem;
      line-height: 1.45;
      margin: 0 0 18px;
    }

    .stake-card {
      position: relative;
      background: var(--bg-card);
      border: 2px solid rgba(255, 255, 255, 0.08);
      border-radius: 16px;
      padding: 16px 18px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      cursor: pointer;
      transition: border-color 0.15s ease, transform 0.1s ease;
      overflow: hidden;
    }

    .stake-card::before {
      content: "";
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 8px;
      background: var(--accent-green);
    }

    .stake-card[data-stake="20"]::before {
      background: var(--accent-cyan);
    }

    .stake-card[data-stake="50"]::before {
      background: var(--accent-red);
    }

    .stake-card.active {
      border-color: var(--accent-gold);
      background: var(--bg-card-alt);
    }

    .stake-left {
      display: flex;
      align-items: center;
      gap: 16px;
      padding-left: 6px;
    }

    .stake-num {
      font-size: 2.1rem;
      font-weight: 900;
      line-height: 1;
    }

    .stake-title {
      font-weight: 700;
      font-size: 1.02rem;
    }

    .stake-desc {
      color: var(--text-muted);
      font-size: 0.85rem;
      margin-top: 3px;
    }

    .radio-dot {
      width: 24px;
      height: 24px;
      border-radius: 50%;
      border: 2px solid var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .stake-card.active .radio-dot {
      border-color: var(--accent-gold);
    }

    .stake-card.active .radio-dot::after {
      content: "";
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: var(--accent-gold);
    }

    .btn-primary {
      width: 100%;
      margin-top: 12px;
      padding: 16px 20px;
      border: none;
      border-radius: 14px;
      background: var(--accent-gold);
      color: #102022;
      font-weight: 900;
      font-size: 1.1rem;
      cursor: pointer;
    }

    .credit-split {
      text-align: center;
      color: var(--text-muted);
      font-size: 0.88rem;
      margin-top: 10px;
    }

    .panel {
      background: var(--bg-card);
      border-radius: 16px;
      padding: 16px;
      margin-bottom: 14px;
      border: 1px solid var(--border-subtle);
    }

    .stats-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      text-align: center;
      margin-top: 12px;
    }

    .stat-val {
      font-size: 1.25rem;
      font-weight: 800;
    }

    .stat-lbl {
      font-size: 0.78rem;
      color: var(--text-muted);
    }

    .caller-ball {
      width: 92px;
      height: 92px;
      border-radius: 50%;
      background: #ffffff;
      color: #0b2225;
      margin: 0 auto 12px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      border: 6px solid var(--accent-gold);
      font-weight: 900;
    }

    .caller-letter {
      font-size: 0.8rem;
      color: var(--accent-red);
    }

    .caller-num {
      font-size: 2rem;
      line-height: 1;
    }

    .watch-banner {
      background: rgba(245, 180, 27, 0.14);
      border: 1px solid rgba(245, 180, 27, 0.45);
      border-radius: 12px;
      padding: 12px 14px;
      margin: 12px 0;
    }

    .cartela-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 6px;
      margin-top: 10px;
    }

    .cartela-btn {
      padding: 8px 0;
      border-radius: 8px;
      border: 1px solid rgba(255, 255, 255, 0.14);
      background: var(--bg-card-alt);
      color: #fff;
      font-weight: 700;
      font-size: 0.82rem;
      cursor: pointer;
    }

    .cartela-btn.yours {
      background: var(--accent-gold);
      color: #092022;
      border-color: var(--accent-gold);
    }

    .cartela-btn.taken {
      opacity: 0.45;
      text-decoration: line-through;
    }

    .wallet-actions {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin: 14px 0;
    }

    .wallet-box {
      border-radius: 16px;
      padding: 18px 14px;
      font-weight: 800;
      font-size: 1.05rem;
      border: none;
      cursor: pointer;
      text-align: left;
    }

    .wallet-deposit {
      background: var(--accent-green);
      color: #0b2010;
    }

    .wallet-withdraw {
      background: var(--accent-cyan);
      color: #072226;
    }

    .profile-avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: var(--accent-gold);
      color: #0b2022;
      font-size: 1.6rem;
      font-weight: 900;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
    }

    .bottom-nav {
      position: fixed;
      left: 12px;
      right: 12px;
      bottom: 10px;
      background: #0c393d;
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 22px;
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      padding: 6px;
      z-index: 50;
    }

    .nav-btn {
      background: transparent;
      border: none;
      color: var(--text-muted);
      padding: 8px 4px;
      border-radius: 16px;
      font-weight: 700;
      font-size: 0.85rem;
      cursor: pointer;
    }

    .nav-btn.active {
      background: var(--accent-gold);
      color: #0b2022;
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.72);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 18px;
      z-index: 100;
    }

    .modal-backdrop.open {
      display: flex;
    }

    .modal-card {
      background: var(--bg-card);
      border: 2px solid var(--accent-gold);
      border-radius: 18px;
      padding: 20px;
      width: 100%;
      max-width: 360px;
      text-align: center;
    }

    .hidden {
      display: none;
    }
  </style>
</head>
<body>
  <header class="top-header">
    <div class="brand">
      <span class="brand-diamond"></span>
      <span>Future Bingo</span>
    </div>
    <div class="balance-pill">
      <span class="lbl">Balance</span>
      <span class="val" id="headerBalance">50 ETB</span>
    </div>
  </header>

  <main class="container">
    <!-- TAB 1: GAME -->
    <section id="tab-game">
      <div id="lobbyView">
        <h1 class="page-title">Choose a table</h1>
        <p class="page-subtitle">
          Pick a stake, then choose up to 3 cartelas. The first cartela with a full line wins. A line is a row, column, diagonal or the four corners.
        </p>

        <div class="stake-card active" data-stake="10" data-lines="1">
          <div class="stake-left">
            <div class="stake-num">10</div>
            <div>
              <div class="stake-title">ETB per cartela</div>
              <div class="stake-desc">1 line to win. Winner takes 80% of all stakes</div>
            </div>
          </div>
          <div class="radio-dot"></div>
        </div>

        <div class="stake-card" data-stake="20" data-lines="2">
          <div class="stake-left">
            <div class="stake-num">20</div>
            <div>
              <div class="stake-title">ETB per cartela</div>
              <div class="stake-desc">2 lines to win. Winner takes 80% of all stakes</div>
            </div>
          </div>
          <div class="radio-dot"></div>
        </div>

        <div class="stake-card" data-stake="50" data-lines="3">
          <div class="stake-left">
            <div class="stake-num">50</div>
            <div>
              <div class="stake-title">ETB per cartela</div>
              <div class="stake-desc">3 lines to win. Winner takes 80% of all stakes</div>
            </div>
          </div>
          <div class="radio-dot"></div>
        </div>

        <button class="btn-primary" id="playStakeBtn">Play for 10 ETB</button>
        <div class="credit-split">Main 0 ETB, play credits 50 ETB</div>
      </div>

      <div id="roundView" class="hidden">
        <div class="panel">
          <div class="caller-ball">
            <span class="caller-letter" id="callerLetter">N</span>
            <span class="caller-num" id="callerNum">41</span>
          </div>
          <div class="stats-row">
            <div>
              <div class="stat-val" id="prizePool">880</div>
              <div class="stat-lbl">ETB prize</div>
            </div>
            <div>
              <div class="stat-val" id="calledCount">9/75</div>
              <div class="stat-lbl">called</div>
            </div>
            <div>
              <div class="stat-val" id="cartelaCount">110</div>
              <div class="stat-lbl">cartelas</div>
            </div>
          </div>

          <div class="watch-banner" id="watchBanner">
            <div style="font-weight:800;">You are watching this round</div>
            <div style="font-size:0.88rem;color:var(--text-muted);">Join the next round to play or pick cartelas below.</div>
          </div>

          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <span style="font-size:0.86rem;color:var(--text-muted);" id="selectedSummary">Your cartelas (0/3). Tap to pick.</span>
            <button onclick="simulateWinnerModal()" style="background:var(--bg-card-alt);color:#fff;border:1px solid var(--border-subtle);border-radius:8px;padding:6px 10px;font-size:0.8rem;cursor:pointer;">Demo Winner</button>
          </div>

          <div class="cartela-grid" id="cartelaGrid"></div>
        </div>

        <button class="btn-primary" id="backToLobbyBtn" style="background:var(--bg-card-alt);color:#fff;">← Change Table</button>
      </div>
    </section>

    <!-- TAB 2: HISTORY -->
    <section id="tab-history" class="hidden">
      <h1 class="page-title">Game History</h1>
      <div class="panel">
        <div style="color:var(--text-muted);font-size:0.92rem;">No completed rounds recorded yet. Pick a table under <b>Game</b> to join a round.</div>
      </div>
    </section>

    <!-- TAB 3: WALLET -->
    <section id="tab-wallet" class="hidden">
      <h1 class="page-title">Wallet</h1>
      <div class="panel">
        <div style="font-size:2rem;font-weight:900;">0 ETB</div>
        <div style="color:var(--accent-green);font-weight:700;margin-bottom:12px;">Can be withdrawn</div>
        <div style="display:flex;justify-content:space-between;color:var(--text-muted);border-top:1px solid var(--border-subtle);padding-top:10px;">
          <span>Play credits</span>
          <span style="color:#fff;font-weight:800;">50 ETB</span>
        </div>
      </div>

      <div class="wallet-actions">
        <button class="wallet-box wallet-deposit" onclick="showWalletNotice('Deposit via CBE or Telebirr in the bot chat using /verify REF AMOUNT.')">Deposit ↓</button>
        <button class="wallet-box wallet-withdraw" onclick="showWalletNotice('You need one approved deposit before you can withdraw.')">Withdraw ↑</button>
      </div>
      <p class="page-subtitle" id="walletNotice">You need one approved deposit before you can withdraw.</p>

      <div class="panel">
        <div style="font-weight:800;margin-bottom:6px;">Requests</div>
        <div style="color:var(--text-muted);font-size:0.9rem;">No requests yet.</div>
      </div>
    </section>

    <!-- TAB 4: PROFILE -->
    <section id="tab-profile" class="hidden">
      <h1 class="page-title">Profile</h1>
      <div class="panel" style="text-align:center;">
        <div class="profile-avatar" id="profileInitial">F</div>
        <div style="font-size:1.25rem;font-weight:800;" id="profileName">Player</div>
        <div style="color:var(--accent-green);font-size:0.88rem;margin-top:4px;">Verified player</div>

        <div class="stats-row" style="margin-top:18px;">
          <div class="panel" style="margin:0;padding:12px 6px;">
            <div class="stat-val">0</div>
            <div class="stat-lbl">Game wins</div>
          </div>
          <div class="panel" style="margin:0;padding:12px 6px;">
            <div class="stat-val">0</div>
            <div class="stat-lbl">Invites</div>
          </div>
          <div class="panel" style="margin:0;padding:12px 6px;">
            <div class="stat-val">0</div>
            <div class="stat-lbl">Earned</div>
          </div>
        </div>

        <div class="panel" style="margin-top:14px;display:flex;align-items:center;justify-content:space-between;text-align:left;">
          <div>
            <div style="font-weight:700;">Sound effects</div>
            <div style="font-size:0.82rem;color:var(--text-muted);">Toggle game audio</div>
          </div>
          <input type="checkbox" id="soundToggle" checked style="width:20px;height:20px;accent-color:var(--accent-gold);" />
        </div>
      </div>
    </section>
  </main>

  <nav class="bottom-nav">
    <button class="nav-btn active" data-tab="game">Game</button>
    <button class="nav-btn" data-tab="history">History</button>
    <button class="nav-btn" data-tab="wallet">Wallet</button>
    <button class="nav-btn" data-tab="profile">Profile</button>
  </nav>

  <div class="modal-backdrop" id="winnerModal">
    <div class="modal-card">
      <div style="color:var(--accent-gold);font-weight:800;">Bingo</div>
      <div style="font-size:1.6rem;font-weight:900;margin:4px 0;">Winner</div>
      <div style="color:var(--text-muted);margin-bottom:14px;">880 ETB prize pool</div>
      <button class="btn-primary" onclick="closeWinnerModal()">Continue</button>
    </div>
  </div>

  <script>
    const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    if (tg) {
      tg.ready();
      tg.expand();
      const user = tg.initDataUnsafe && tg.initDataUnsafe.user;
      if (user && user.first_name) {
        document.getElementById('profileName').textContent = user.first_name;
        document.getElementById('profileInitial').textContent = user.first_name.charAt(0).toUpperCase();
      }
    }

    let selectedStake = 10;
    const pickedCartelas = new Set();
    const takenCartelas = new Set([30, 32, 41, 44, 46]);

    document.querySelectorAll('.stake-card').forEach(card => {
      card.addEventListener('click', () => {
        document.querySelectorAll('.stake-card').forEach(c => c.classList.remove('active'));
        card.classList.add('active');
        selectedStake = parseInt(card.dataset.stake, 10);
        document.getElementById('playStakeBtn').textContent = `Play for ${selectedStake} ETB`;
      });
    });

    function renderCartelaGrid() {
      const grid = document.getElementById('cartelaGrid');
      grid.innerHTML = '';
      for (let i = 1; i <= 48; i++) {
        const btn = document.createElement('button');
        btn.className = 'cartela-btn';
        btn.textContent = i;
        if (pickedCartelas.has(i)) btn.classList.add('yours');
        else if (takenCartelas.has(i)) btn.classList.add('taken');

        btn.addEventListener('click', () => {
          if (takenCartelas.has(i)) return;
          if (pickedCartelas.has(i)) {
            pickedCartelas.delete(i);
          } else {
            if (pickedCartelas.size >= 3) return;
            pickedCartelas.add(i);
          }
          document.getElementById('selectedSummary').textContent = `Your cartelas (${pickedCartelas.size}/3). Tap again to remove.`;
          renderCartelaGrid();
        });
        grid.appendChild(btn);
      }
    }

    document.getElementById('playStakeBtn').addEventListener('click', () => {
      document.getElementById('lobbyView').classList.add('hidden');
      document.getElementById('roundView').classList.remove('hidden');
      renderCartelaGrid();
    });

    document.getElementById('backToLobbyBtn').addEventListener('click', () => {
      document.getElementById('roundView').classList.add('hidden');
      document.getElementById('lobbyView').classList.remove('hidden');
    });

    document.querySelectorAll('.nav-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const tab = btn.dataset.tab;
        ['game', 'history', 'wallet', 'profile'].forEach(name => {
          document.getElementById(`tab-${name}`).classList.toggle('hidden', name !== tab);
        });
      });
    });

    function showWalletNotice(msg) {
      document.getElementById('walletNotice').textContent = msg;
    }

    function simulateWinnerModal() {
      document.getElementById('winnerModal').classList.add('open');
    }

    function closeWinnerModal() {
      document.getElementById('winnerModal').classList.remove('open');
    }
  </script>
</body>
</html>
