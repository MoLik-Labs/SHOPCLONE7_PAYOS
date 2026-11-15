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
        }

        /** How many times a loader actually ran — used by the performance test. */
        public function loads()
        {
            return $this->loads;
        }

        public function size()
        {
            return count($this->values);
        }
    }
}
