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
      --bg-dark: #06282b;
      --bg-header: #083034;
      --card-bg: #0d3b3e;
      --card-hover: #124a4e;
      --accent-gold: #f5b41b;
      --accent-green: #8cc63f;
      --accent-cyan: #57b9c7;
      --accent-red: #e0533c;
      --accent-purple: #6f42c1;
      --text-main: #ffffff;
      --text-muted: #9ac0c2;
      --line-border: rgba(255, 255, 255, 0.12);
    }

    * {
      box-sizing: border-box;
      user-select: none;
      -webkit-tap-highlight-color: transparent;
    }

    body {
      margin: 0;
      padding: 0 0 90px 0;
      background-color: var(--bg-dark);
      color: var(--text-main);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      overflow-x: hidden;
      min-height: 100vh;
    }

    .d-none {
      display: none !important;
    }

    .app-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 16px;
      background: var(--bg-header);
      border-bottom: 3px solid var(--accent-gold);
    }

    .brand-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 800;
      font-size: 1.1rem;
    }

    .brand-gem {
      width: 20px;
      height: 20px;
      background: var(--accent-gold);
      transform: rotate(45deg);
      border-radius: 4px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .brand-gem::after {
      content: "";
      width: 7px;
      height: 7px;
      background: var(--accent-red);
      border-radius: 2px;
    }

    .balance-box {
      text-align: right;
    }

    .balance-box small {
      display: block;
      font-size: 0.68rem;
      color: var(--text-muted);
    }

    .balance-box strong {
      font-size: 1.05rem;
      color: var(--accent-gold);
      font-weight: 800;
    }

    .container {
      max-width: 520px;
      margin: 0 auto;
      padding: 14px;
    }

    .section-title {
      font-size: 1.55rem;
      font-weight: 800;
      margin: 4px 0 6px;
    }

    .section-sub {
      color: var(--text-muted);
      font-size: 0.85rem;
      line-height: 1.45;
      margin: 0 0 14px;
    }

    .stake-option {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: var(--card-bg);
      border: 2px solid var(--line-border);
      border-radius: 16px;
      padding: 14px 16px;
      margin-bottom: 12px;
      cursor: pointer;
      overflow: hidden;
      transition: border-color 0.15s ease, background 0.15s ease;
    }

    .stake-option::before {
      content: "";
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 8px;
      background: var(--accent-green);
    }

    .stake-option[data-stake="20"]::before {
      background: var(--accent-cyan);
    }

    .stake-option[data-stake="50"]::before {
      background: var(--accent-red);
    }

    .stake-option.selected {
      border-color: var(--accent-gold);
      background: var(--card-hover);
    }

    .stake-left {
      display: flex;
      align-items: center;
      gap: 14px;
      padding-left: 6px;
    }

    .stake-amount {
      font-size: 2rem;
      font-weight: 900;
      line-height: 1;
      color: #fff;
    }

    .stake-name {
      font-weight: 700;
      font-size: 0.98rem;
      color: #fff;
    }

    .stake-rule {
      font-size: 0.82rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .radio-ring {
      width: 22px;
      height: 22px;
      border-radius: 50%;
      border: 2px solid var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .stake-option.selected .radio-ring {
      border-color: var(--accent-gold);
    }

    .stake-option.selected .radio-ring::after {
      content: "";
      width: 11px;
      height: 11px;
      border-radius: 50%;
      background: var(--accent-gold);
    }

    .card-panel {
      background: var(--card-bg);
      border: 1px solid var(--line-border);
      border-radius: 16px;
      padding: 14px;
      margin-bottom: 12px;
    }

    .cartela-picker-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 6px;
      max-height: 170px;
      overflow-y: auto;
      padding: 4px 2px;
    }

    .cartela-circle {
      aspect-ratio: 1;
      border-radius: 50%;
      background: #144448;
      border: 1px solid #266066;
      color: #fff;
      font-size: 0.68rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: transform 0.12s ease;
    }

    .cartela-circle.taken {
      background: #3a1d24;
      border-color: #7a3342;
      color: #f8a5b3;
      opacity: 0.65;
      text-decoration: line-through;
      cursor: not-allowed;
    }

    .cartela-circle.selected {
      background: var(--accent-gold);
      border-color: #ffd561;
      color: #092022;
      transform: scale(1.06);
      font-weight: 900;
    }

    .btn-gold {
      width: 100%;
      border: none;
      border-radius: 14px;
      background: var(--accent-gold);
      color: #0a2022;
      font-weight: 900;
      font-size: 1.02rem;
      padding: 14px 16px;
      cursor: pointer;
    }

    .btn-secondary-dark {
      width: 100%;
      border: 1px solid var(--line-border);
      border-radius: 12px;
      background: var(--card-hover);
      color: #fff;
      font-weight: 700;
      font-size: 0.9rem;
      padding: 10px 14px;
      cursor: pointer;
    }

    .watch-card {
      background: rgba(245, 180, 27, 0.14);
      border: 1px solid rgba(245, 180, 27, 0.45);
      border-radius: 12px;
      padding: 10px 12px;
      margin-bottom: 10px;
    }

    .stat-row-3 {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
      text-align: center;
      margin: 10px 0;
    }

    .stat-box {
      background: rgba(0, 0, 0, 0.22);
      border: 1px solid var(--line-border);
      border-radius: 12px;
      padding: 8px 6px;
    }

    .stat-box .num {
      font-size: 1.15rem;
      font-weight: 800;
      display: block;
    }

    .stat-box .lbl {
      font-size: 0.72rem;
      color: var(--text-muted);
    }

    .recent-balls-row {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .recent-ball {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 0.82rem;
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.22);
    }

    .recent-ball.latest {
      background: #ff8c00;
      border-color: var(--accent-gold);
      color: #fff;
      transform: scale(1.06);
    }

    .play-split {
      display: grid;
      grid-template-columns: 5fr 7fr;
      gap: 10px;
      align-items: start;
    }

    .board-75-wrap {
      background: #1b2427;
      border-radius: 10px;
      padding: 6px;
    }

    .bingo-5col {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 3px;
    }

    .bingo-head {
      color: #091d1f;
      font-weight: 900;
      text-align: center;
      padding: 4px 0;
      border-radius: 4px;
      font-size: 0.78rem;
    }

    .num-75-cell {
      background: #2b3538;
      color: #d6e2e4;
      font-size: 0.64rem;
      font-weight: 700;
      padding: 3px 0;
      text-align: center;
      border-radius: 3px;
    }

    .num-75-cell.called {
      background: #ff9800;
      color: #111;
      font-weight: 800;
    }

    .num-75-cell.called.latest {
      background: #4ade80;
      color: #06282b;
    }

    .bingo-cell {
      aspect-ratio: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #e9f2f2;
      color: #092629;
      border-radius: 5px;
      font-size: 0.78rem;
      font-weight: 800;
    }

    .bingo-cell.marked,
    .bingo-cell.free {
      background: var(--accent-purple);
      color: #fff;
    }

    .bingo-cell.win-highlight {
      background: #198754;
      color: #fff;
    }

    .wallet-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin: 12px 0;
    }

    .wallet-action-btn {
      border: none;
      border-radius: 16px;
      padding: 16px 14px;
      font-weight: 800;
      font-size: 1rem;
      cursor: pointer;
      text-align: left;
    }

    .wallet-action-btn.deposit {
      background: var(--accent-green);
      color: #081e0c;
    }

    .wallet-action-btn.withdraw {
      background: var(--accent-cyan);
      color: #062024;
    }

    .profile-badge-circle {
      width: 62px;
      height: 62px;
      border-radius: 50%;
      background: var(--accent-gold);
      color: #082022;
      font-size: 1.5rem;
      font-weight: 900;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
    }

    .bottom-nav {
      position: fixed;
      bottom: 8px;
      left: 10px;
      right: 10px;
      max-width: 500px;
      margin: 0 auto;
      background: #0c393d;
      border: 1px solid rgba(255, 255, 255, 0.16);
      border-radius: 24px;
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      padding: 5px;
      z-index: 1050;
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.6);
    }

    .nav-tab {
      text-align: center;
      padding: 8px 6px;
      border-radius: 18px;
      font-size: 0.8rem;
      font-weight: 700;
      color: var(--text-muted);
      cursor: pointer;
    }

    .nav-tab.active {
      background: var(--accent-gold);
      color: #092022;
      font-weight: 800;
    }

    .modal-backdrop-custom {
      position: fixed;
      inset: 0;
      background: rgba(4, 14, 17, 0.88);
      z-index: 2000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 14px;
    }

    .modal-box-custom {
      width: 100%;
      max-width: 370px;
      max-height: 90vh;
      overflow-y: auto;
      background: #142a2e;
      border: 1px solid var(--accent-gold);
      border-radius: 16px;
      padding: 16px;
      text-align: center;
    }
  </style>
</head>
<body>
  <header class="app-header">
    <div class="brand-wrap">
      <span class="brand-gem"></span>
      <span>Future Bingo</span>
    </div>
    <div class="balance-box">
      <small>Balance</small>
      <strong id="balance-display">0.00 ETB</strong>
    </div>
  </header>

  <!-- SCREEN 1: GAME SETUP -->
  <section id="screen-game" class="container">
    <h1 class="section-title">Choose a table</h1>
    <p class="section-sub">
      Pick a stake, then choose up to 3 cartelas (1 - 400). The first cartela with a full line wins. A line is a row, column, diagonal or the four corners.
    </p>

    <div id="table-options-list">
      <div class="stake-option selected" data-stake="10" data-lines="1" onclick="selectTable(10, this)">
        <div class="stake-left">
          <div class="stake-amount">10</div>
          <div>
            <div class="stake-name">ETB per cartela</div>
            <div class="stake-rule">1 line to win. Winner takes 80% of all stakes</div>
          </div>
        </div>
        <div class="radio-ring"></div>
      </div>

      <div class="stake-option" data-stake="20" data-lines="2" onclick="selectTable(20, this)">
        <div class="stake-left">
          <div class="stake-amount">20</div>
          <div>
            <div class="stake-name">ETB per cartela</div>
            <div class="stake-rule">2 lines to win. Winner takes 80% of all stakes</div>
          </div>
        </div>
        <div class="radio-ring"></div>
      </div>

      <div class="stake-option" data-stake="50" data-lines="3" onclick="selectTable(50, this)">
        <div class="stake-left">
          <div class="stake-amount">50</div>
          <div>
            <div class="stake-name">ETB per cartela</div>
            <div class="stake-rule">3 lines to win. Winner takes 80% of all stakes</div>
          </div>
        </div>
        <div class="radio-ring"></div>
      </div>
    </div>

    <div class="card-panel">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:0.78rem;">
        <strong style="color:var(--accent-gold);">Pick Cartela (1 - 400)</strong>
        <span style="color:var(--text-muted);">● Yours &nbsp; ● Taken</span>
      </div>
      <div id="cartela-grid-picker" class="cartela-picker-grid"></div>
      <div id="selected-cartela-info" style="text-align:center;color:var(--accent-gold);font-size:0.76rem;font-weight:700;margin-top:8px;">
        Your cartelas (0/3). Tap a number to select/remove.
      </div>
    </div>

    <button class="btn-gold" id="play-btn" onclick="joinSelectedTable()">Select at least 1 Cartela</button>
    <div id="credit-breakdown-text" style="text-align:center;color:var(--text-muted);font-size:0.82rem;margin-top:8px;">
      Main 0.00 ETB, play credits 0.00 ETB
    </div>
  </section>

  <!-- SCREEN 2: WATCHING ACTIVE ROUND -->
  <section id="screen-wait-active" class="container d-none">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;font-size:0.78rem;">
      <span>Game <strong id="wait-game-code">--</strong></span>
      <span>Prize <strong id="wait-derash">0.00</strong></span>
      <span>Calls <strong id="wait-calls">0</strong></span>
      <button class="btn-secondary-dark" style="width:auto;padding:6px 12px;" onclick="leaveToSetup()">Exit</button>
    </div>

    <div class="watch-card">
      <div style="font-weight:800;color:var(--accent-gold);">You are watching this round</div>
      <div style="font-size:0.82rem;color:var(--text-muted);">Join the next round to play once this round finishes.</div>
    </div>

    <div class="recent-balls-row" id="wait-recent-balls" style="justify-content:center;margin-bottom:10px;"></div>

    <div class="board-75-wrap">
      <div class="bingo-5col" style="margin-bottom:4px;">
        <div class="bingo-head" style="background:#ff9800;">B</div>
        <div class="bingo-head" style="background:#4ade80;">I</div>
        <div class="bingo-head" style="background:#38bdf8;">N</div>
        <div class="bingo-head" style="background:#f87171;">G</div>
        <div class="bingo-head" style="background:#c084fc;">O</div>
      </div>
      <div class="bingo-5col" id="wait-master-grid"></div>
    </div>
  </section>

  <!-- SCREEN 3: LOBBY TIMER -->
  <section id="screen-lobby" class="container d-none" style="text-align:center;">
    <div class="card-panel" style="margin-top:16px;">
      <div style="font-weight:800;margin-bottom:6px;">Room Starting Soon</div>
      <div id="lobby-prize-pool" style="font-size:1.6rem;font-weight:900;color:var(--accent-gold);">0.00 ETB</div>
      <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:10px;">Estimated Prize Pool (80% of Stakes)</div>
      <div id="lobby-timer" style="font-size:1.3rem;font-weight:800;color:var(--accent-gold);">00:15</div>
      <div id="lobby-player-count" style="font-size:0.8rem;color:var(--text-muted);margin-top:8px;">Players Joined: 1</div>
    </div>
  </section>

  <!-- SCREEN 4: ACTIVE PLAYROOM -->
  <section id="screen-playroom" class="container d-none">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:8px;font-size:0.76rem;">
      <span>Game <strong id="play-game-code">--</strong></span>
      <span>Prize <strong id="play-derash">0.00</strong></span>
      <span>Calls <strong id="play-calls">0</strong></span>
      <button id="sound-toggle" class="btn-secondary-dark" style="width:auto;padding:5px 10px;font-size:0.75rem;" onclick="toggleSound()">🔊 Sound</button>
      <button class="btn-secondary-dark" style="width:auto;padding:5px 10px;font-size:0.75rem;" onclick="leaveToSetup()">Exit</button>
    </div>

    <div class="recent-balls-row" id="play-recent-balls" style="justify-content:center;margin-bottom:10px;"></div>

    <div class="play-split">
      <div class="board-75-wrap">
        <div class="bingo-5col" style="margin-bottom:4px;">
          <div class="bingo-head" style="background:#ff9800;">B</div>
          <div class="bingo-head" style="background:#4ade80;">I</div>
          <div class="bingo-head" style="background:#38bdf8;">N</div>
          <div class="bingo-head" style="background:#f87171;">G</div>
          <div class="bingo-head" style="background:#c084fc;">O</div>
        </div>
        <div class="bingo-5col" id="play-master-grid"></div>
      </div>

      <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:0.78rem;">
          <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
            <input type="checkbox" id="auto-checkbox" checked onchange="toggleAutoMode()">
            <span>Auto <strong id="auto-status-label" style="color:#4ade80;">ON</strong></span>
          </label>
          <strong style="color:var(--accent-gold);" id="active-cartela-badge">Board #1</strong>
        </div>

        <div class="bingo-5col" style="margin-bottom:4px;">
          <div class="bingo-head" style="background:#ff9800;">B</div>
          <div class="bingo-head" style="background:#4ade80;">I</div>
          <div class="bingo-head" style="background:#38bdf8;">N</div>
          <div class="bingo-head" style="background:#f87171;">G</div>
          <div class="bingo-head" style="background:#c084fc;">O</div>
        </div>
        <div id="board" class="bingo-5col" style="margin-bottom:10px;"></div>

        <button id="bingo-btn" class="btn-gold" style="background:#198754;color:#fff;padding:10px;" disabled>
          Automatic (<span id="bingo-btn-derash">0</span>)
        </button>
      </div>
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

    <p class="section-sub" id="wallet-helper-note">You need one approved deposit before you can withdraw.</p>

    <div class="card-panel">
      <div style="font-weight:800;margin-bottom:4px;">Requests</div>
      <div style="color:var(--text-muted);font-size:0.85rem;">No requests yet.</div>
    </div>
  </section>

  <!-- SCREEN 7: PROFILE -->
  <section id="screen-profile" class="container d-none">
    <h1 class="section-title">Profile</h1>
    <div class="card-panel" style="text-align:center;">
      <div class="profile-badge-circle" id="profile-avatar">F</div>
      <div style="font-size:1.2rem;font-weight:800;" id="profile-name">Player</div>
      <div style="color:var(--accent-green);font-size:0.85rem;margin-top:2px;">Verified player</div>
      <div style="color:var(--text-muted);font-size:0.78rem;margin-top:4px;">ID: <span id="profile-id">--</span></div>

      <div class="stat-row-3" style="margin-top:14px;">
        <div class="stat-box">
          <span class="num" id="stat-wins">0</span>
          <span class="lbl">Game wins</span>
        </div>
        <div class="stat-box">
          <span class="num" id="stat-invites">0</span>
          <span class="lbl">Invites</span>
        </div>
        <div class="stat-box">
          <span class="num" id="stat-earned">0</span>
          <span class="lbl">Earned</span>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;text-align:left;background:rgba(0,0,0,0.2);padding:10px 12px;border-radius:12px;margin-top:12px;">
        <div>
          <div style="font-weight:700;font-size:0.9rem;">Sound effects</div>
          <div style="font-size:0.76rem;color:var(--text-muted);">Toggle game audio</div>
        </div>
        <input type="checkbox" id="profile-sound-toggle" checked onchange="toggleSound()" style="width:20px;height:20px;accent-color:var(--accent-gold);" />
      </div>
    </div>
  </section>

  <!-- WINNER MODAL -->
  <div id="winner-modal" class="modal-backdrop-custom d-none">
    <div class="modal-box-custom">
      <div style="color:var(--accent-gold);font-weight:900;font-size:1.2rem;letter-spacing:1px;">BINGO!</div>
      <div id="winner-list-banner" style="background:#e67e00;border-radius:10px;padding:10px;margin:10px 0;font-weight:700;"></div>
      <div id="winner-board-title" style="font-size:0.85rem;font-weight:700;color:var(--accent-gold);margin-bottom:6px;">Board #--</div>
      <div class="bingo-5col" style="margin-bottom:4px;">
        <div class="bingo-head" style="background:#ff9800;">B</div>
        <div class="bingo-head" style="background:#4ade80;">I</div>
        <div class="bingo-head" style="background:#38bdf8;">N</div>
        <div class="bingo-head" style="background:#f87171;">G</div>
        <div class="bingo-head" style="background:#c084fc;">O</div>
      </div>
      <div id="winner-board-grid" class="bingo-5col" style="margin-bottom:12px;"></div>
      <button type="button" class="btn-gold" onclick="playAgainFromModal()">Play Again</button>
    </div>
  </div>

  <!-- BOTTOM NAVIGATION -->
  <nav class="bottom-nav">
    <div class="nav-tab active" onclick="switchTab('game')" id="nav-game">Game</div>
    <div class="nav-tab" onclick="switchTab('history')" id="nav-history">History</div>
    <div class="nav-tab" onclick="switchTab('wallet')" id="nav-wallet">Wallet</div>
    <div class="nav-tab" onclick="switchTab('profile')" id="nav-profile">Profile</div>
  </nav>

  <script>
    const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    if (tg) {
      tg.ready();
      tg.expand();
    }

    function getUserId() {
      if (tg && tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.id) {
        const id = tg.initDataUnsafe.user.id.toString();
        localStorage.setItem('Future_bingo_tg_id', id);
        return id;
      }
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.has('telegram_id')) {
        const id = urlParams.get('telegram_id');
        localStorage.setItem('Future_bingo_tg_id', id);
        return id;
      }
      return localStorage.getItem('Future_bingo_tg_id') || '730967613';
    }

    const telegramId = getUserId();
    const user = tg && tg.initDataUnsafe ? tg.initDataUnsafe.user : null;
    const playerName = user && user.first_name ? user.first_name : 'Player';

    document.getElementById('profile-name').textContent = playerName;
    document.getElementById('profile-avatar').textContent = (playerName.charAt(0) || 'P').toUpperCase();
    document.getElementById('profile-id').textContent = telegramId || 'Guest';

    let balance = 0.00;
    let selectedStake = 10;
    let selectedCartelaNumbers = [];
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

    function updatePlayButtonLabel() {
      const btn = document.getElementById('play-btn');
      btn.textContent = selectedCartelaNumbers.length > 0
        ? `Play for ${selectedStake * selectedCartelaNumbers.length} ETB`
        : 'Select at least 1 Cartela';
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
        if (!isTaken) {
          circle.onclick = () => toggleCartelaNumber(i);
        }
        gridContainer.appendChild(circle);
      }
      document.getElementById('selected-cartela-info').textContent =
        `Your cartelas (${selectedCartelaNumbers.length}/3). Tap a number to select/remove.`;
      updatePlayButtonLabel();
    }

    function toggleCartelaNumber(num) {
      if (takenCartelas.includes(num)) return;
      const idx = selectedCartelaNumbers.indexOf(num);
      if (idx > -1) {
        selectedCartelaNumbers.splice(idx, 1);
      } else {
        if (selectedCartelaNumbers.length < 3) {
          selectedCartelaNumbers.push(num);
        } else {
          alert('You can select a maximum of 3 cartelas per round.');
          return;
        }
      }
      buildCartelaPickerGrid();
    }

    function selectTable(stake, element) {
      document.querySelectorAll('.stake-option').forEach(el => {
        el.classList.remove('selected');
        const radio = el.querySelector('input[type="radio"]');
        if (radio) radio.checked = false;
      });
      element.classList.add('selected');
      const activeRadio = element.querySelector('input[type="radio"]');
      if (activeRadio) activeRadio.checked = true;
      selectedStake = stake;
      updatePlayButtonLabel();
      pollSetupRoomStatus();
    }

    function stopRoomPolling() {
      if (roomPollInterval) {
        clearInterval(roomPollInterval);
        roomPollInterval = null;
      }
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

    function switchTab(tab) {
      document.querySelectorAll('.bottom-nav .nav-tab').forEach(n => n.classList.remove('active'));
      const tabEl = document.getElementById(`nav-${tab}`);
      if (tabEl) tabEl.classList.add('active');
      showOnlyScreen(tab);
      if (tab === 'game') {
        buildCartelaPickerGrid();
        pollSetupRoomStatus();
      } else if (tab === 'history') {
        renderHistory();
      }
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
          <div><strong>Game ${item.code}</strong> <span style="color:var(--text-muted);margin-left:6px;">Board #${item.board}</span></div>
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
      if (btn) btn.textContent = soundEnabled ? '🔊 Sound' : '🔇 Muted';
      const profToggle = document.getElementById('profile-sound-toggle');
      if (profToggle) profToggle.checked = soundEnabled;
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
      ut.rate = 1.05;
      ut.pitch = 1.1;
      window.speechSynthesis.speak(ut);
    }

    function updateBalanceUI(newBal) {
      balance = parseFloat(newBal) || 0.00;
      const formatted = `${balance.toFixed(2)} ETB`;
      document.getElementById('balance-display').textContent = formatted;
      document.getElementById('wallet-balance').textContent = formatted;
      document.getElementById('credit-breakdown-text').textContent = `Main 0.00 ETB, play credits ${formatted}`;
    }

    function syncBalance() {
      if (!telegramId) return;
      fetch(`api.php?action=get_balance&telegram_id=${encodeURIComponent(telegramId)}`)
        .then(res => res.json())
        .then(data => {
          if (data.success && data.balance !== undefined) updateBalanceUI(data.balance);
        })
        .catch(() => {});
    }

    function render75Grid(containerId, calledSet, latestNum) {
      const container = document.getElementById(containerId);
      if (!container) return;
      container.innerHTML = '';
      for (let r = 1; r <= 15; r++) {
        for (let c = 0; c < 5; c++) {
          const n = c * 15 + r;
          const cell = document.createElement('div');
          const isCalled = calledSet.has(n);
          const isLatest = isCalled && n === latestNum;
          cell.className = 'num-75-cell' + (isCalled ? ' called' : '') + (isLatest ? ' latest' : '');
          cell.textContent = n;
          container.appendChild(cell);
        }
      }
    }

    function renderRecentBalls(containerId, calledArr) {
      const box = document.getElementById(containerId);
      if (!box) return;
      const lastThree = calledArr.slice(-3).reverse();
      box.innerHTML = '';
      for (let i = 0; i < 3; i++) {
        const n = lastThree[i];
        const el = document.createElement('div');
        if (n !== undefined) {
          el.className = 'recent-ball' + (i === 0 ? ' latest' : '');
          el.textContent = `${getBallLetter(n)}${n}`;
        } else {
          el.className = 'recent-ball';
          el.textContent = '--';
        }
        box.appendChild(el);
      }
    }

    function renderPlayerCard() {
      const boardEl = document.getElementById('board');
      boardEl.innerHTML = '';
      card.forEach((num, idx) => {
        const cell = document.createElement('div');
        const isFree = idx === 12;
        const isMarked = isFree || marked[idx];
        cell.className = 'bingo-cell' + (isFree ? ' free' : (isMarked ? ' marked' : ''));
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
      if (!roomId || winnerModalShownForRoom === roomId) return;
      winnerModalShownForRoom = roomId;
      stopRoomPolling();
      syncBalance();
      if ('speechSynthesis' in window) window.speechSynthesis.cancel();
      sounds.winFanfare();
      if (tg && tg.HapticFeedback) tg.HapticFeedback.notificationOccurred('success');

      const banner = document.getElementById('winner-list-banner');
      if (!winners || winners.length === 0) {
        banner.innerHTML = `<div>Round Ended</div>`;
      } else {
        banner.innerHTML = winners.map(w => `
          <div style="margin-bottom:4px;">
            <span>${w.name || 'Player'}</span> has won <strong>${Number(w.prize || 0).toFixed(2)} ETB</strong>
          </div>
        `).join('');
      }

      const firstWinner = (winners && winners.length > 0) ? winners[0] : null;
      const winBoard = (firstWinner && Array.isArray(firstWinner.board) && firstWinner.board.length === 25)
        ? firstWinner.board
        : generateDeterministicCard(activeCartelaNumber);
      const winLineSet = new Set((firstWinner && Array.isArray(firstWinner.winning_line)) ? firstWinner.winning_line : []);
      const calledSet = new Set((roomObj && Array.isArray(roomObj.called_numbers)) ? roomObj.called_numbers : calledNumbers);

      document.getElementById('winner-board-title').textContent =
        firstWinner ? `Board ${firstWinner.cartela_number}` : `Board ${activeCartelaNumber}`;

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

      const didIWin = Array.isArray(winners) && winners.some(w => String(w.telegram_id) === String(telegramId));
      const myEntry = Array.isArray(winners) ? winners.find(w => String(w.telegram_id) === String(telegramId)) : null;
      gameHistory.unshift({
        code: currentGameCode,
        board: activeCartelaNumber,
        won: didIWin,
        status: didIWin ? `Won +${Number(myEntry.prize || 0).toFixed(2)} ETB` : 'Finished'
      });

      document.getElementById('winner-modal').classList.remove('d-none');
    }

    function playAgainFromModal() {
      document.getElementById('winner-modal').classList.add('d-none');
      currentRoomId = null;
      selectedCartelaNumbers = [];
      leaveToSetup();
    }

    function renderWaitActiveScreen(room) {
      showOnlyScreen('wait-active');
      document.getElementById('wait-game-code').textContent = room.game_code || '--';
      document.getElementById('wait-derash').textContent = Number(room.derash || 0).toFixed(2);
      document.getElementById('wait-calls').textContent = room.calls_count ?? 0;

      const arr = Array.isArray(room.called_numbers) ? room.called_numbers : [];
      const latest = arr.length > 0 ? arr[arr.length - 1] : null;
      renderRecentBalls('wait-recent-balls', arr);
      render75Grid('wait-master-grid', new Set(arr), latest);
    }

    function renderPlayroomScreen(room) {
      showOnlyScreen('playroom');
      currentGameCode = room.game_code || '--';
      currentDerash = Number(room.derash || 0);
      const arr = Array.isArray(room.called_numbers) ? room.called_numbers : [];
      const latest = arr.length > 0 ? arr[arr.length - 1] : null;

      document.getElementById('play-game-code').textContent = currentGameCode;
      document.getElementById('play-derash').textContent = currentDerash.toFixed(2);
      document.getElementById('play-calls').textContent = arr.length;
      document.getElementById('active-cartela-badge').textContent = `Board #${activeCartelaNumber}`;

      const derashEl = document.getElementById('bingo-btn-derash');
      if (derashEl) derashEl.textContent = Math.round(currentDerash);

      if (latest !== null && latest !== lastAnnouncedNumber) {
        lastAnnouncedNumber = latest;
        sounds.ballPop();
        announceBall(getBallLetter(latest), latest);
      }

      calledNumbers = arr;
      renderRecentBalls('play-recent-balls', arr);
      render75Grid('play-master-grid', new Set(arr), latest);

      const calledSet = new Set(calledNumbers);
      let changed = false;
      card.forEach((num, idx) => {
        if (idx !== 12 && calledSet.has(num) && !marked[idx]) {
          marked[idx] = true;
          changed = true;
        }
      });
      if (changed) {
        sounds.daubMatch();
        renderPlayerCard();
      }
    }

    function startRoomPolling(roomId) {
      stopRoomPolling();
      currentRoomId = roomId;

      const tick = () => {
        if (!currentRoomId) return;
        fetch(`api.php?action=get_room_state&room_id=${encodeURIComponent(currentRoomId)}`)
          .then(res => res.json())
          .then(data => {
            if (!data.success || !data.room) return;
            const room = data.room;
            if (room.status === 'waiting') {
              showOnlyScreen('lobby');
              const secs = Math.max(0, Number(room.lobby_seconds_left || 0));
              const m = Math.floor(secs / 60);
              const s = secs % 60;
              document.getElementById('lobby-timer').textContent =
                `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
              document.getElementById('lobby-prize-pool').textContent = `${Number(room.derash || 0).toFixed(2)} ETB`;
              document.getElementById('lobby-player-count').textContent = `Players Joined: ${room.players_count || 1}`;
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
            } else if (room.status === 'finished') {
              if (room.winners && room.winners.length > 0) {
                openWinnerModal(room.room_id, room.winners, room);
              } else {
                stopRoomPolling();
                currentRoomId = null;
                alert('Round ended with no winner.');
                leaveToSetup();
              }
            }
          })
          .catch(() => {});
      };

      tick();
      roomPollInterval = setInterval(tick, 1200);
    }

    function pollSetupRoomStatus() {
      fetch(`api.php?action=get_room_state&stake=${encodeURIComponent(selectedStake)}`)
        .then(res => res.json())
        .then(data => {
          if (!data.success) return;
          if (data.room && data.room.status === 'waiting') {
            takenCartelas = Array.isArray(data.room.taken_cartelas) ? data.room.taken_cartelas : [];
            selectedCartelaNumbers = selectedCartelaNumbers.filter(n => !takenCartelas.includes(n));
            buildCartelaPickerGrid();
          } else {
            takenCartelas = [];
            buildCartelaPickerGrid();
          }
        })
        .catch(() => {});
    }

    function startWaitForActiveGame(room) {
      currentRoomId = room.room_id;
      renderWaitActiveScreen(room);
      stopRoomPolling();
      roomPollInterval = setInterval(() => {
        fetch(`api.php?action=get_room_state&room_id=${encodeURIComponent(room.room_id)}`)
          .then(res => res.json())
          .then(data => {
            if (!data.success || !data.room) return;
            if (data.room.status === 'active') {
              renderWaitActiveScreen(data.room);
            } else if (data.room.status === 'finished') {
              stopRoomPolling();
              currentRoomId = null;
              alert('Current game finished! You can now pick your cartelas for the next round.');
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

      const formData = new FormData();
      formData.append('action', 'join_room');
      formData.append('telegram_id', telegramId);
      formData.append('first_name', playerName);
      formData.append('stake', selectedStake);
      formData.append('cartela_numbers', selectedCartelaNumbers.join(','));

      fetch('api.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
          if (data.already_started && data.room) {
            startWaitForActiveGame(data.room);
            return;
          }
          if (!data.success) {
            sounds.errorBuzz();
            alert(data.error || 'Could not join table.');
            pollSetupRoomStatus();
            return;
          }

          if (data.new_balance !== undefined) updateBalanceUI(data.new_balance);
          card = [];
          marked = Array(25).fill(false);
          marked[12] = true;
          winnerModalShownForRoom = null;
          startRoomPolling(data.room_id);
        })
        .catch(() => alert('Network error while joining table.'));
    }

    function startInstantDeposit() {
      const amountStr = prompt('Enter deposit amount in ETB (minimum 10):', '50');
      if (!amountStr) return;
      const amountNum = parseFloat(amountStr);
      if (isNaN(amountNum) || amountNum < 10) {
        alert('Please enter a valid deposit amount (minimum 10 ETB).');
        return;
      }

      const txRef = prompt('Enter your Telebirr or CBE Transaction ID / Receipt Reference:');
      if (!txRef || !txRef.trim()) return;

      const formData = new FormData();
      formData.append('telegram_id', telegramId);
      formData.append('amount', amountNum.toFixed(2));
      formData.append('tx_ref', txRef.trim());

      fetch('verify_payment.php', { method: 'POST', body: formData })
        .then(async res => {
          const text = await res.text();
          try { return JSON.parse(text); } catch { return { success: false, message: text || 'Invalid server response' }; }
        })
        .then(data => {
          if (data.success) {
            if (data.new_balance !== undefined) updateBalanceUI(data.new_balance);
            alert(data.message || 'Deposit request submitted!');
          } else {
            alert(data.message || data.error || 'Transaction ID not found or already claimed.');
          }
        })
        .catch(err => alert('Network error: ' + err.message));
    }

    function requestWithdrawal() {
      const amount = prompt(`Enter amount to withdraw (Available: ${balance.toFixed(2)} ETB):`, '50');
      if (!amount) return;
      const amtNum = parseFloat(amount);
      if (isNaN(amtNum) || amtNum <= 0 || amtNum > balance) {
        alert('Invalid amount or insufficient balance.');
        return;
      }
      const method = prompt('Choose payout method:\n1. Telebirr\n2. CBE', 'Telebirr');
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
        .then(async res => {
          const text = await res.text();
          try { return JSON.parse(text); } catch { return { success: false, error: text || 'Invalid server response' }; }
        })
        .then(data => {
          if (data.success) {
            updateBalanceUI(data.new_balance);
            alert(data.message || 'Withdrawal requested successfully!');
          } else {
            alert(data.error || data.message || 'Withdrawal failed.');
          }
        })
        .catch(err => alert('Network error: ' + err.message));
    }

    setInterval(syncBalance, 5000);
    window.addEventListener('focus', syncBalance);

    buildCartelaPickerGrid();
    syncBalance();
    pollSetupRoomStatus();
  </script>
</body>
</html>
