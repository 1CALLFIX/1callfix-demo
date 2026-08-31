/**
 * Customer web app bundle.
 *
 * Deliberately tiny and dependency-free — this file is on the critical path
 * of the homepage, and this codebase ships no Alpine, no jQuery and no UI
 * library. Everything interactive that the server can own is owned by
 * Livewire on the server; only behaviour that genuinely has to run in the
 * browser lives here.
 */

import './carousel';
import './search-bar';
