/*
 * Emergency hotfix.
 *
 * The growth suite introduced a MutationObserver feedback loop: its observer
 * called renderLevel(), renderLevel() rewrote innerHTML, and that DOM write
 * immediately triggered the same observer again. On clients with a profile
 * this could saturate the main thread and make the PWA appear frozen.
 *
 * Keep the module intentionally inert until the growth features are restored
 * with idempotent rendering and a narrowly-scoped observer.
 */
(function(){'use strict';})();
