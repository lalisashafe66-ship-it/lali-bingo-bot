<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
  <title>Future Bingo</title>
  <script src="https://telegram.org/js/telegram-web-app.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --bg-dark: #06282b;
      --card-bg: #0d363a;
      --accent-gold: #FFC107;
      --accent-green: #198754;
      --accent-purple: #6f42c1;
    }
    body {
      background-color: var(--bg-dark);
      color: #ffffff;
      font-family: system-ui, -apple-system, sans-serif;
      user-select: none;
      padding-top: 8px;
      padding-bottom: 85px;
      overflow-x: hidden;
    }
    .top-strip {
      background: linear-gradient(90deg, #ffc107, #ff9800, #00bcd4, #e91e63, #ffc107);
      height: 4px;
      width: 100%;
      margin-bottom: 10px;
    }
    .card-custom {
      background-color: var(--card-bg);
      border: 1px solid #1d4d52;
      border-radius: 12px;
      padding: 10px 12px;
      margin-bottom: 8px;
    }
    .table-option {
      background-color: var(--card-bg);
      border: 2px solid #215258;
      border-radius: 10px;
      padding: 8px 12px;
      margin-bottom: 6px;
      cursor: pointer;
      transition: all 0.2s ease;
      text-align: left;
    }
    .table-option.selected {
      border-color: var(--accent-gold);
      background-color: #144348;
    }
    .table-option .text-muted,
    .table-option small,
    .table-option p {
      color: #cbd5e1 !important;
      font-size: 0.75rem;
    }
    .bottom-nav {
      position: fixed;
      bottom: 8px;
      left: 10px;
      right: 10px;
      background-color: #092c30;
      border: 1px solid #1d4e56;
      border-radius: 30px;
      display: flex;
      justify-content: space-around;
      padding: 6px 4px;
      z-index: 1050;
      box-shadow: 0 4px 20px rgba(0,0,0,0.6);
    }
    .nav-item-custom {
      color: #8a9b9e;
      text-align: center;
      font-size: 0.75rem;
      cursor: pointer;
      text-decoration: none;
      padding: 6px 14px;
      border-radius: 20px;
      transition: 0.2s;
    }
    .nav-item-custom.active {
      color: #000;
      background-color: var(--accent-gold);
      font-weight: 800;
      box-shadow: 0 0 10px rgba(255, 193, 7, 0.4);
    }
    .cartela-picker-grid {
      display: grid;
      grid-template-columns: repeat(8, 1fr);
      gap: 4px;
      max-height: 150px;
      overflow-y: auto;
      padding: 2px;
    }
    .cartela-circle {
      aspect-ratio: 1;
      border-radius: 50%;
      background-color: #153c42;
      border: 1px solid #28545c;
      color: #ffffff;
      font-size: 0.6rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .cartela-circle.taken {
      background-color: #3a1d24;
      border-color: #7a3342;
      color: #f8a5b3;
      opacity: 0.65;
      cursor: not-allowed;
    }
    .cartela-circle.selected {
      background-color: var(--accent-gold);
      color: #000000;
      border-color: #ffca2c;
      transform: scale(1.08);
      box-shadow: 0 0 8px rgba(255, 193, 7, 0.6);
    }
    .legend-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
    }
    .bingo-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 4px;
      max-width: 280px;
      margin: 0 auto;
    }
    .bingo-header {
      color: #000;
      font-weight: 800;
      text-align: center;
      padding: 4px 0;
      border-radius: 4px;
      font-size: 0.8rem;
    }
    .bingo-cell {
      aspect-ratio: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #e9f2f2;
      color: #092629;
      border: 1px solid #b5cfd2;
      border-radius: 5px;
      font-size: 0.8rem;
      font-weight: 800;
    }
    .bingo-cell.marked {
      background: var(--accent-purple);
      border-color: #9b6bff;
      color: #fff;
    }
    .bingo-cell.free {
      background: var(--accent-purple);
      border-color: #9b6bff;
      color: #fff;
      font-size: 0.7rem;
    }
    .bingo-cell.win-highlight {
      background: #198754;
      border-color: #51e39d;
      color: #fff;
    }
    .stat-pill {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.14);
      border-radius: 10px;
      padding: 3px 8px;
      min-width: 54px;
      text-align: center;
    }
    .stat-pill-label {
      font-size: 0.6rem;
      color: #8ee3dc;
      display: block;
      line-height: 1.1;
    }
    .stat-pill-value {
      font-size: 0.75rem;
      font-weight: 800;
      color: #ffffff;
      line-height: 1.15;
    }
    .recent-ball {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 0.85rem;
      color: #fff;
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.2);
    }
    .recent-ball.latest {
      background: #ff8c00;
      border-color: #ffc107;
      transform: scale(1.08);
      box-shadow: 0 0 12px rgba(255, 140, 0, 0.6);
    }
    .board-75-wrap {
      background: #1e2426;
      border-radius: 8px;
      padding: 5px;
    }
    .board-75-headers {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 2px;
      margin-bottom: 3px;
    }
    .board-75-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 2px;
    }
    .num-75-cell {
      background: #2f373a;
      color: #d8e2e4;
      font-size: 0.65rem;
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
    .auto-bar {
      background: rgba(0, 0, 0, 0.25);
      border-radius: 10px;
      padding: 6px 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }
    .switch-btn {
      width: 46px;
      height: 24px;
      border-radius: 999px;
      background: #6c757d;
      position: relative;
      cursor: pointer;
      transition: background 0.2s;
      border: none;
      padding: 0;
    }
    .switch-btn.on {
      background: #ff8c00;
    }
    .switch-knob {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: #fff;
      position: absolute;
      top: 3px;
      left: 3px;
      transition: transform 0.2s;
    }
    .switch-btn.on .switch-knob {
      transform: translateX(22px);
    }
    .winner-modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(4, 14, 17, 0.88);
      z-index: 2000;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 14px;
    }
    .winner-modal-card {
      width: 100%;
      max-width: 380px;
      max-height: 90vh;
      overflow-y: auto;
      background: #1b252c;
      border: 1px solid #2e404d;
      border-radius: 16px;
      padding: 16px;
      text-align: center;
      box-shadow: 0 12px 32px rgba(0, 0, 0, 0.75);
    }
    .winner-banner {
      background: #e67e00;
      border-radius: 10px;
      padding: 10px;
      margin-bottom: 12px;
      color: #fff;
      font-weight: 700;
    }
    .winner-prize-badge {
      background: #198754;
      color: #fff;
      padding: 2px 7px;
      border-radius: 5px;
      font-weight: 800;
    }
  </style>
</head>
<body class="px-2">
  <div class="d-flex justify-content-between align-items-center mb-1">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size: 1.2rem;">🔶</span>
      <h6 class="fw-bold mb-0 text-white">Future Bingo</h6>
    </div>
    <div class="text-end">
      <small class="text-muted d-block" style="font-size: 0.65rem;">Balance</small>
      <span class="fw-bold text-warning fs-6" id="balance-display">0.00 ETB</span>
    </div>
  </div>

  <div class="top-strip"></div>

  <!-- GAME SETUP SCREEN -->
  <div id="screen-game" class="container px-1">
    <h5 class="fw-bold text-white mb-1" style="font-size: 1.05rem;">Choose a table</h5>
    <p class="text-muted small mb-2" style="font-size: 0.72rem;">Pick a stake, then choose up to 3 cartelas (1 - 400). Winner takes 80% of stakes.</p>

    <div id="table-options-list" class="mb-2">
      <div class="table-option selected" onclick="selectTable(10, this)">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h6 class="fw-bold text-warning mb-0">10 <small class="text-muted">ETB/cartela</small></h6>
            <p class="mb-0">1 line to win (80% pool)</p>
          </div>
          <input type="radio" name="stake" checked class="form-check-input m-0">
        </div>
      </div>

      <div class="table-option" onclick="selectTable(20, this)">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h6 class="fw-bold text-warning mb-0">20 <small class="text-muted">ETB/cartela</small></h6>
            <p class="mb-0">1 line to win (80% pool)</p>
          </div>
          <input type="radio" name="stake" class="form-check-input m-0">
        </div>
      </div>

      <div class="table-option" onclick="selectTable(50, this)">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h6 class="fw-bold text-warning mb-0">50 <small class="text-muted">ETB/cartela</small></h6>
            <p class="mb-0">1 line to win (80% pool)</p>
          </div>
          <input type="radio" name="stake" class="form-check-input m-0">
        </div>
      </div>
    </div>

    <div class="card-custom mb-2">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="small fw-bold text-warning" style="font-size: 0.75rem;">Pick Cartela (1 - 400):</span>
        <div class="small text-muted d-flex align-items-center gap-2" style="font-size: 0.7rem;">
          <span><span class="legend-dot bg-warning align-middle"></span> Yours</span>
          <span><span class="legend-dot align-middle" style="background:#7a3342;"></span> Taken</span>
        </div>
      </div>
      <div id="cartela-grid-picker" class="cartela-picker-grid mb-1"></div>
      <p class="text-warning text-center small fw-bold mb-0" id="selected-cartela-info" style="font-size: 0.7rem;">Your cartelas (0/3). Tap to select.</p>
    </div>

    <div class="mb-2">
      <button class="btn btn-warning w-100 fw-bold py-2 shadow" id="play-btn" onclick="joinSelectedTable()" style="font-size: 0.95rem;">
        Select at least 1 Cartela
      </button>
    </div>
  </div>

  <!-- WAIT SCREEN WHEN GAME IS ALREADY IN PROGRESS -->
  <div id="screen-wait-active" class="container px-1 d-none">
    <div class="d-flex flex-wrap gap-1 justify-content-between align-items-center mb-2">
      <div class="stat-pill"><span class="stat-pill-label">Game</span><span class="stat-pill-value" id="wait-game-code">--</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Derash</span><span class="stat-pill-value" id="wait-derash">0.00</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Bonus</span><span class="stat-pill-value" id="wait-bonus">0</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Players</span><span class="stat-pill-value" id="wait-players">0</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Calls</span><span class="stat-pill-value" id="wait-calls">0</span></div>
      <button class="btn btn-danger btn-sm fw-bold py-1 px-2" onclick="leaveToSetup()">Exit</button>
    </div>

    <div class="d-flex gap-2 justify-content-center mb-2" id="wait-recent-balls"></div>

    <div class="row g-2 align-items-center">
      <div class="col-5">
        <div class="board-75-wrap">
          <div class="board-75-headers">
            <div class="bingo-header" style="background:#ff9800;">B</div>
            <div class="bingo-header" style="background:#4ade80;">I</div>
            <div class="bingo-header" style="background:#22c55e;">N</div>
            <div class="bingo-header" style="background:#ef4444;">G</div>
            <div class="bingo-header" style="background:#a855f7;">O</div>
          </div>
          <div class="board-75-grid" id="wait-master-grid"></div>
        </div>
      </div>
      <div class="col-7 text-center py-4">
        <div class="fw-bold fs-5 mb-2" style="color:#ff9800; line-height:1.45;">
          እባክዎ ይህ ጨዋታ እስከሚጨርስ ይጠብቁ!
        </div>
        <div class="text-light small opacity-75">
          Game in progress. You will be able to join the next round automatically once this game ends.
        </div>
      </div>
    </div>
  </div>

  <!-- LOBBY WAITING SCREEN -->
  <div id="screen-lobby" class="container text-center d-none">
    <div class="card-custom my-3 py-3">
      <h6 class="text-light fw-bold mb-1">⏳ Room Starting Soon</h6>
      <div class="fs-4 fw-bold text-warning my-1" id="lobby-prize-pool">0.00 ETB</div>
      <p class="text-muted small mb-2" style="font-size: 0.75rem;">Estimated Prize Pool (80% of Stakes)</p>
      <div class="badge bg-dark text-warning fs-6 px-3 py-1" id="lobby-timer">00:15</div>
      <p class="text-muted small mt-2 mb-0" id="lobby-player-count" style="font-size: 0.75rem;">👥 Players Joined: 1</p>
    </div>
  </div>

  <!-- ACTIVE PLAYROOM SCREEN -->
  <div id="screen-playroom" class="container px-1 d-none">
    <div class="d-flex flex-wrap gap-1 justify-content-between align-items-center mb-2">
      <div class="stat-pill"><span class="stat-pill-label">Game</span><span class="stat-pill-value" id="play-game-code">--</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Derash</span><span class="stat-pill-value" id="play-derash">0.00</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Bonus</span><span class="stat-pill-value" id="play-bonus">0</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Bet</span><span class="stat-pill-value" id="play-bet">0</span></div>
      <div class="stat-pill"><span class="stat-pill-label">Calls</span><span class="stat-pill-value" id="play-calls">0</span></div>
      <button class="btn btn-outline-light btn-sm py-1 px-2" style="font-size:0.7rem;" onclick="leaveToSetup()">Exit</button>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="d-flex gap-2 align-items-center" id="play-recent-balls"></div>
      <button id="sound-toggle" class="btn btn-outline-warning btn-sm py-0 px-2" style="font-size: 0.75rem;" onclick="toggleSound()">🔊 Sound</button>
    </div>

    <div class="row g-2 align-items-start">
      <div class="col-5">
        <div class="board-75-wrap">
          <div class="board-75-headers">
            <div class="bingo-header" style="background:#ff9800;">B</div>
            <div class="bingo-header" style="background:#4ade80;">I</div>
            <div class="bingo-header" style="background:#22c55e;">N</div>
            <div class="bingo-header" style="background:#ef4444;">G</div>
            <div class="bingo-header" style="background:#a855f7;">O</div>
          </div>
          <div class="board-75-grid" id="play-master-grid"></div>
        </div>
      </div>

      <div class="col-7">
        <div class="auto-bar">
          <div class="d-flex align-items-center gap-2">
            <span class="fw-bold small">Auto</span>
            <button type="button" class="switch-btn on" id="auto-switch" onclick="toggleAutoMode()">
              <span class="switch-knob"></span>
            </button>
            <span class="fw-bold small" id="auto-status-label" style="color:#4ade80;">ON</span>
          </div>
          <span class="badge bg-dark text-warning" id="active-cartela-badge">Board #1</span>
        </div>

        <div class="bingo-grid mb-1">
          <div class="bingo-header" style="background:#ff9800;">B</div>
          <div class="bingo-header" style="background:#4ade80;">I</div>
          <div class="bingo-header" style="background:#22c55e;">N</div>
          <div class="bingo-header" style="background:#ef4444;">G</div>
          <div class="bingo-header" style="background:#a855f7;">O</div>
        </div>
        <div id="board" class="bingo-grid mb-2"></div>

        <div class="d-grid">
          <button id="bingo-btn" class="btn fw-bold shadow py-2" style="background:#198754; color:#fff;" disabled>
            Automatic (<span id="bingo-btn-derash">0</span>)
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- HISTORY SCREEN -->
  <div id="screen-history" class="container d-none">
    <h5 class="fw-bold text-white mb-2" style="font-size: 1.05rem;">Game History</h5>
    <div class="card-custom" id="history-list">
      <p class="text-muted small text-center my-2" style="font-size: 0.75rem;">No recent games recorded yet.</p>
    </div>
  </div>

  <!-- WALLET SCREEN -->
  <div id="screen-wallet" class="container d-none">
    <h5 class="fw-bold text-white mb-2" style="font-size: 1.05rem;">Wallet & Funds</h5>
    <div class="card-custom text-center mb-2 py-2">
      <p class="text-muted mb-0" style="font-size: 0.75rem;">Available Balance</p>
      <h4 class="text-warning fw-bold mb-0" id="wallet-balance">0.00 ETB</h4>
    </div>

    <div class="d-grid gap-2">
      <button class="btn btn-success fw-bold py-2 btn-sm" onclick="startInstantDeposit()">
        💳 Deposit & Verify Transaction ID
      </button>
      <button class="btn btn-outline-warning fw-bold py-2 btn-sm" onclick="requestWithdrawal()">
        💸 Request Withdrawal
      </button>
    </div>
  </div>

  <!-- PROFILE SCREEN -->
  <div id="screen-profile" class="container d-none">
    <h5 class="fw-bold text-white mb-2" style="font-size: 1.05rem;">Player Profile</h5>
    <div class="card-custom">
      <p class="mb-1" style="font-size: 0.8rem;"><strong>Name:</strong> <span id="profile-name">Player</span></p>
      <p class="mb-1" style="font-size: 0.8rem;"><strong>Telegram ID:</strong> <span id="profile-id">--</span></p>
      <p class="mb-0" style="font-size: 0.8rem;"><strong>Status:</strong> <span class="badge bg-success" style="font-size: 0.65rem;">Verified Active</span></p>
    </div>
  </div>

  <!-- WINNER MODAL -->
  <div id="winner-modal" class="winner-modal-backdrop d-none">
    <div class="winner-modal-card">
      <h4 class="fw-bold mb-2" style="color:#ff8c00; letter-spacing:1px;">BINGO!</h4>
      <div class="winner-banner" id="winner-list-banner"></div>
      <div class="mb-2 small fw-bold text-warning" id="winner-board-title">Board #--</div>
      <div class="bingo-grid mb-1">
        <div class="bingo-header" style="background:#ff9800;">B</div>
        <div class="bingo-header" style="background:#4ade80;">I</div>
        <div class="bingo-header" style="background:#22c55e;">N</div>
        <div class="bingo-header" style="background:#ef4444;">G</div>
        <div class="bingo-header" style="background:#a855f7;">O</div>
      </div>
      <div id="winner-board-grid" class="bingo-grid mb-3"></div>
      <button type="button" class="btn w-100 fw-bold py-2" style="background:#ff8c00; color:#fff;" onclick="playAgainFromModal()">
        Play Again
      </button>
    </div>
  </div>

  <!-- BOTTOM PILL NAVIGATION -->
  <div class="bottom-nav">
    <div class="nav-item-custom active" onclick="switchTab('game')" id="nav-game"><div>Game</div></div>
    <div class="nav-item-custom" onclick="switchTab('history')" id="nav-history"><div>History</div></div>
    <div class="nav-item-custom" onclick="switchTab('wallet')" id="nav-wallet"><div>Wallet</div></div>
    <div class="nav-item-custom" onclick="switchTab('profile')" id="nav-profile"><div>Profile</div></div>
  </div>

  <script>
    const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
    if (tg) tg.expand();

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
          alert("You can select a maximum of 3 cartelas per round.");
          return;
        }
      }
      buildCartelaPickerGrid();
      document.getElementById('play-btn').textContent = selectedCartelaNumbers.length > 0
        ? `Play for ${selectedStake * selectedCartelaNumbers.length} ETB`
        : `Select at least 1 Cartela`;
    }

    function selectTable(stake, element) {
      document.querySelectorAll('.table-option').forEach(el => {
        el.classList.remove('selected');
        el.querySelector('input').checked = false;
      });
      element.classList.add('selected');
      element.querySelector('input').checked = true;
      selectedStake = stake;
      if (selectedCartelaNumbers.length > 0) {
        document.getElementById('play-btn').textContent = `Play for ${stake * selectedCartelaNumbers.length} ETB`;
      }
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
      document.querySelectorAll('.bottom-nav .nav-item-custom').forEach(n => n.classList.remove('active'));
      if (tab === 'game') {
        showOnlyScreen('game');
        document.getElementById('nav-game').classList.add('active');
        buildCartelaPickerGrid();
        pollSetupRoomStatus();
      } else if (tab === 'history') {
        showOnlyScreen('history');
        document.getElementById('nav-history').classList.add('active');
        renderHistory();
      } else if (tab === 'wallet') {
        showOnlyScreen('wallet');
        document.getElementById('nav-wallet').classList.add('active');
      } else if (tab === 'profile') {
        showOnlyScreen('profile');
        document.getElementById('nav-profile').classList.add('active');
      }
      syncBalance();
    }

    function renderHistory() {
      const box = document.getElementById('history-list');
      if (gameHistory.length === 0) {
        box.innerHTML = `<p class="text-muted small text-center my-2" style="font-size: 0.75rem;">No recent games recorded yet.</p>`;
        return;
      }
      box.innerHTML = gameHistory.map(item => `
        <div class="d-flex justify-content-between align-items-center py-1 border-bottom border-secondary small">
          <div>
            <strong>Game ${item.code}</strong>
            <span class="text-muted ms-1">Board #${item.board}</span>
          </div>
          <span class="fw-bold ${item.won ? 'text-success' : 'text-warning'}">${item.status}</span>
        </div>
      `).join('');
    }

    let soundEnabled = true;
    let audioCtx = null;

    function initAudio() {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
    }

    function toggleSound() {
      soundEnabled = !soundEnabled;
      document.getElementById('sound-toggle').textContent = soundEnabled ? '🔊 Sound' : '🔇 Muted';
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
      document.getElementById('balance-display').textContent = `${balance.toFixed(2)} ETB`;
      document.getElementById('wallet-balance').textContent = `${balance.toFixed(2)} ETB`;
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
        cell.className = 'bingo-cell' + (isFree ? ' free marked' : (isMarked ? ' marked' : ''));
        cell.textContent = isFree ? '★' : num;
        boardEl.appendChild(cell);
      });
    }

    function toggleAutoMode() {
      autoMode = !autoMode;
      const btn = document.getElementById('auto-switch');
      const label = document.getElementById('auto-status-label');
      if (autoMode) {
        btn.classList.add('on');
        label.textContent = 'ON';
        label.style.color = '#4ade80';
      } else {
        btn.classList.remove('on');
        label.textContent = 'OFF';
        label.style.color = '#ff8c00';
      }
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
          <div class="mb-1">
            <span>${w.name || 'Player'}</span> has won
            <span class="winner-prize-badge">${Number(w.prize || 0).toFixed(2)} ETB</span>
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
      document.getElementById('wait-bonus').textContent = room.bonus ?? 0;
      document.getElementById('wait-players').textContent = room.players_count ?? 0;
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
      document.getElementById('play-bonus').textContent = room.bonus ?? 0;
      document.getElementById('play-bet').textContent = room.players_count ?? 0;
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
              document.getElementById('lobby-player-count').textContent = `👥 Players Joined: ${room.players_count || 1}`;
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
                alert('⏰ Round ended with no winner.');
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
              alert('✅ Current game finished! You can now pick your cartelas for the next round.');
              leaveToSetup();
            }
          })
          .catch(() => {});
      }, 1500);
    }

    function joinSelectedTable() {
      if (selectedCartelaNumbers.length === 0) {
        alert("Please select at least 1 cartela before playing.");
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
      const amountStr = prompt("Enter deposit amount in ETB (minimum 10):", "50");
      if (!amountStr) return;
      const amountNum = parseFloat(amountStr);
      if (isNaN(amountNum) || amountNum < 10) {
        alert("Please enter a valid deposit amount (minimum 10 ETB).");
        return;
      }

      const txRef = prompt("Enter your Telebirr or CBE Transaction ID / Receipt Reference:");
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
            if (data.new_balance !== undefined) {
              updateBalanceUI(data.new_balance);
            }
            alert(`✅ ${data.message || 'Deposit request submitted!'}`);
          } else {
            alert(`❌ ${data.message || data.error || 'Transaction ID not found or already claimed.'}`);
          }
        })
        .catch(err => alert('Network error: ' + err.message));
    }

    function requestWithdrawal() {
      const amount = prompt(`Enter amount to withdraw (Available: ${balance.toFixed(2)} ETB):`, "50");
      if (!amount) return;
      const amtNum = parseFloat(amount);
      if (isNaN(amtNum) || amtNum <= 0 || amtNum > balance) {
        alert("Invalid amount or insufficient balance.");
        return;
      }
      const method = prompt("Choose payout method:\n1. Telebirr\n2. CBE", "Telebirr");
      if (!method) return;
      const account = prompt(`Enter your ${method} Phone/Account number:`, "");
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
            alert(`✅ ${data.message || 'Withdrawal requested successfully!'}`);
          } else {
            alert(data.error || data.message || 'Withdrawal failed.');
          }
        })
        .catch(err => alert('Network error: ' + err.message));
    }

    setInterval(syncBalance, 5000);
    window.addEventListener("focus", syncBalance);

    buildCartelaPickerGrid();
    syncBalance();
    pollSetupRoomStatus();
  </script>
</body>
</html>
