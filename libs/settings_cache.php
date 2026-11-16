<?php
/*
 * A request-scoped memo for `settings` rows.
 *
 * Why: DB::site() is called for the title, description, keywords, currency, status, session length,
 * page size, every admin toggle … on every page. Each call was a round trip to MySQL for a row that
 * cannot change midway through rendering. On a shared MySQL host the difference is tens of queries
 * per request for a value that is read-only in practice.
 *
 * Why a separate class: it has no I/O, so the cache semantics (one load per key, flush on write) can
 * be tested without a database. DB owns invalidation: every write to `settings` flushes the shared
 * cache, so a page that saves and then reads in the same request still sees the new value.
 *
 * Scope: a PHP process. Static state is reset when the request ends, so a long-running worker that
 * writes settings must flush — which DB::update()/insert()/remove() already do.
 */

if (!defined("IN_SITE")) {
    exit("The Request Not Found");
}

if (!class_exists("SettingsCache")) {
    class SettingsCache
    {
        private $values = array();
        private $loads = 0;
        /** The one-shot map of small settings, or null before the first read. */
        private $map = null;
        private $mapLoads = 0;

        /**
         * Load every small setting in ONE query and keep the map for the rest of the request.
         *
         * The shop reads 20–60 distinct settings per page (title, every toggle, every label). One
         * query per key is the single largest avoidable cost in a page render. Values above the
         * caller's size limit are deliberately absent: a page that needs the 200 KB FAQ blob should
         * pay for it only on the page that prints it, through remember().
         */
        public function remember_all($loader)
        {
            if ($this->map !== null) {
                return $this->map;
            }
            ++$this->mapLoads;
            $map = is_callable($loader) ? call_user_func($loader) : array();
            $this->map = is_array($map) ? $map : array();
            return $this->map;
        }

        public function has_all()
        {
            return $this->map !== null;
        }

        /** How many times the bulk query ran — used by the performance test. */
        public function map_loads()
        {
            return $this->mapLoads;
        }

        /**
         * Return the cached value for $key, or run $loader once and keep its result.
         * A null result is cached too: "the row does not exist" is a fact worth not re-querying.
         */
        public function remember($key, $loader)
        {
            $key = (string) $key;
            if (array_key_exists($key, $this->values)) {
                return $this->values[$key];
            }
            $this->loads++;
            $value = is_callable($loader) ? call_user_func($loader) : null;
            $this->values[$key] = $value;
            return $value;
        }

        public function has($key)
        {
            return array_key_exists((string) $key, $this->values);
        }

        public function flush()
        {
            $this->values = array();
            $this->map = null;
        }

        /** How many times a loader actually ran — used by the performance test. */
        public function loads()
        {
            return $this->loads;
        }

        public function size()
        {
            return count($this->values) + ($this->map === null ? 0 : count($this->map));
        }
    }
}
