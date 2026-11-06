# Graph Report - .  (2026-07-21)

## Corpus Check
- Corpus is ~46,031 words - fits in a single context window. You may not need a graph.

## Summary
- 91 nodes · 124 edges · 16 communities (15 shown, 1 thin omitted)
- Extraction: 85% EXTRACTED · 15% INFERRED · 0% AMBIGUOUS · INFERRED: 18 edges (avg confidence: 0.89)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Chess Engine Core
- Authentication & Sessions
- UI Features & Gameplay Options
- App Entry & PWA Setup
- Online Multiplayer Backend
- Move Validation & Castling
- App Identity & Icons
- Apple Touch Icon

## God Nodes (most connected - your core abstractions)
1. `Changelog — Version 26.07` - 13 edges
2. `chess.html — Main Game File` - 7 edges
3. `legalMoves() — Legal Move Filtering (incl. Antichess forced-capture)` - 7 edges
4. `auth_write_json()` - 6 edges
5. `chess.html — Complete Game Entry Point` - 6 edges
6. `auth_read_json()` - 5 edges
7. `auth_sessions()` - 5 edges
8. `auth_rate_check()` - 5 edges
9. `Chess Project (README)` - 5 edges
10. `makeMove() — Apply Move to Game State (Immutable)` - 5 edges

## Surprising Connections (you probably didn't know these)
- `Built-in JavaScript Chess Engine (Negamax + Alpha-Beta + Quiescence)` --semantically_similar_to--> `engineSrc Script Block — Embedded Chess Engine (Worker Source)`  [INFERRED] [semantically similar]
  README.md → chess.html
- `chess.html — Main Game File` --implements--> `PWA / Offline Support via Service Worker`  [INFERRED]
  chess.html → README.md
- `parseFEN() — FEN String Parser` --implements--> `PGN/FEN Import`  [INFERRED]
  chess.html → changelog.html
- `Chess Project (README)` --references--> `Changelog — Version 26.07`  [EXTRACTED]
  README.md → changelog.html
- `Server-side Clock for Online Games` --conceptually_related_to--> `Online Mode — PHP Backend Polling Architecture`  [INFERRED]
  changelog.html → README.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Built-in Engine Search Pipeline: legalMoves → negamax → quiesce → evalSide** — chess_html_legal_moves, chess_html_negamax, chess_html_quiesce, chess_html_eval_side, chess_html_best_move [EXTRACTED 0.95]
- **Blob Worker Pattern: engineSrc eval'd in main thread + concatenated into Worker Blob** — chess_html_engine_src, chess_html_worker_src, chess_html_blob_worker_pattern [EXTRACTED 0.95]
- **Online Mode Architecture: chess.html (frontend) → chess_online.php (backend) → online_games/ (storage), protected by nginx** — readme_chess_html_entry, readme_chess_online_php, readme_online_games_dir, readme_nginx_conf [EXTRACTED 0.95]

## Communities (16 total, 1 thin omitted)

### Community 0 - "Chess Engine Core"
Cohesion: 0.25
Nodes (14): Antichess (Räuberschach) Game Variant, bestMove() — Top-level Best Move Selector, Blob Worker Pattern — Engine Shared Between Main Thread and Worker, engineSrc Script Block — Embedded Chess Engine (Worker Source), evalSide() — Side-relative Evaluation (inverted for Antichess), evalWhite() — Material + PST Position Evaluation, legalMoves() — Legal Move Filtering (incl. Antichess forced-capture), makeMove() — Apply Move to Game State (Immutable) (+6 more)

### Community 1 - "Authentication & Sessions"
Cohesion: 0.33
Nodes (12): auth_cookie_path(), auth_ip(), auth_new_session(), auth_out(), auth_rate_check(), auth_rate_reset(), auth_read_json(), auth_sessions() (+4 more)

### Community 2 - "UI Features & Gameplay Options"
Cohesion: 0.17
Nodes (13): Board Mode (iPad as Chessboard — Two Player Local), Board Themes (Classic, Wood, Tournament-Green, Night), Drag and Drop Piece Movement (Mouse), Draw Offer and Rematch Features, PGN/FEN Import, Piece Sets (Classic, Comic, Symbols, Bronze), Player Accounts Feature (Online Mode Registration/Login), Premove (Pre-move Queuing) (+5 more)

### Community 3 - "App Entry & PWA Setup"
Cohesion: 0.25
Nodes (11): chess.html — Main Game File, manifest.webmanifest — PWA Manifest Link, PIECE_SVG — Inline SVG Chess Piece Graphics, index.html — Meta Redirect to chess.html, chess.html — Complete Game Entry Point, chess_online.php — Online Mode Backend, Chess Project (README), nginx-chess.snippet.conf — nginx Security + WASM MIME Config (+3 more)

### Community 4 - "Online Multiplayer Backend"
Cohesion: 0.29
Nodes (5): clean_id(), game_file(), load_game(), out(), save_game()

### Community 5 - "Move Validation & Castling"
Cohesion: 0.22
Nodes (9): Chess960 Castling — Full Implementation, attacked() — Square Attack Detection, backRank() — Initial Piece Placement for All Variants, castleMoves() — Castling Move Generation (Standard + Chess960), deriveCastleCols() — King/Rook Start Column Detection, initialState() — Game State Initialization, parseFEN() — FEN String Parser, pseudoMoves() — Pseudo-legal Move Generation (+1 more)

### Community 6 - "App Identity & Icons"
Cohesion: 0.90
Nodes (5): Chess Application, Chess App Icon 1024px, Chess App Icon 192px, Chess App Icon 512px, Chess App Maskable Icon (512px)

## Knowledge Gaps
- **11 isolated node(s):** `Elo Rating System (Local vs Computer + Planned Server-side Online)`, `Premove (Pre-move Queuing)`, `Drag and Drop Piece Movement (Mouse)`, `Sound Effects via Web Audio API`, `Board Mode (iPad as Chessboard — Two Player Local)` (+6 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **1 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Changelog — Version 26.07` connect `UI Features & Gameplay Options` to `Chess Engine Core`, `App Entry & PWA Setup`, `Move Validation & Castling`?**
  _High betweenness centrality (0.163) - this node is a cross-community bridge._
- **Why does `Antichess (Räuberschach) Game Variant` connect `Chess Engine Core` to `UI Features & Gameplay Options`?**
  _High betweenness centrality (0.080) - this node is a cross-community bridge._
- **Why does `Chess Project (README)` connect `App Entry & PWA Setup` to `UI Features & Gameplay Options`?**
  _High betweenness centrality (0.061) - this node is a cross-community bridge._
- **What connects `Elo Rating System (Local vs Computer + Planned Server-side Online)`, `Premove (Pre-move Queuing)`, `Drag and Drop Piece Movement (Mouse)` to the rest of the system?**
  _11 weakly-connected nodes found - possible documentation gaps or missing edges._