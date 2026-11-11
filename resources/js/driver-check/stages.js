/*
|--------------------------------------------------------------------------
| Stages: a page that loads block by block
|--------------------------------------------------------------------------
|
| A heavy page is cut into stages. Each stage is one request for one block:
| it has its own placeholder, its own error with a retry, and it never
| holds the other blocks back. The stages go one after another in the
| order they are named, so the top of the page is filled first; a stage
| marked lazy waits until its block is scrolled into view.
|
|   ...stages({ summary: 'loadSummary', accounts: 'loadAccounts' }),
|
|   init() { this.loadStages(); }
|
| In the blade the block is <x-driver-check.block stage="accounts">.
|
*/

/**
 * @param {Record<string, string>} loaders  stage name -> the component's method that loads it
 */
export function stages(loaders) {
    const names = Object.keys(loaders);

    return {
        stages: Object.fromEntries(names.map((name) => [name, { loading: false, loaded: false, error: null, lazy: false }])),

        stageTimers: [],
        stageObservers: [],

        /* One stage: again and again it keeps what is on the screen until the new answer is in. */
        async loadStage(name) {
            const stage = this.stages[name];

            if (!stage || stage.loading) {
                return;
            }

            stage.loading = true;
            stage.error = null;

            try {
                await this[loaders[name]]();
                stage.loaded = true;
            } catch (error) {
                stage.error = error?.message || 'HTTP error';
            } finally {
                stage.loading = false;
            }
        },

        /* The named stages (all of them by default) in their order; a lazy one not yet seen is left for later. */
        async loadStages(only = names) {
            for (const name of only) {
                const stage = this.stages[name];

                if (stage && (!stage.lazy || stage.loaded)) {
                    await this.loadStage(name);
                }
            }
        },

        /* The block's stage is loaded when the block comes near the screen. */
        stageOnSight(element, name) {
            const stage = this.stages[name];

            if (!stage) {
                return;
            }

            stage.lazy = true;

            if (typeof IntersectionObserver === 'undefined') {
                stage.lazy = false;
                this.loadStage(name);

                return;
            }

            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    observer.disconnect();
                    stage.lazy = false;

                    if (!stage.loaded) {
                        this.loadStage(name);
                    }
                }
            }, { rootMargin: '200px 0px' });

            observer.observe(element);
            this.stageObservers.push(observer);
        },

        anyStageLoading() {
            return names.some((name) => this.stages[name].loading);
        },

        /*
         * Looks again every `ms`, only while the tab is on the screen and
         * `when()` says so. The stages already loaded are the ones renewed.
         */
        watchStages(ms, only = names, when = () => true) {
            this.stageTimers.push(everyVisible(ms, () => {
                if (when.call(this) && !this.anyStageLoading()) {
                    this.loadStages(only.filter((name) => this.stages[name]?.loaded));
                }
            }));
        },

        stopStages() {
            this.stageTimers.forEach((stop) => stop());
            this.stageObservers.forEach((observer) => observer.disconnect());
            this.stageTimers = [];
            this.stageObservers = [];
        },
    };
}

/**
 * A timer that keeps quiet while the tab is hidden, and looks at once when
 * the tab is opened again after a miss. Returns the function that ends it.
 */
export function everyVisible(ms, callback) {
    let missed = false;

    const timer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            callback();
        } else {
            missed = true;
        }
    }, ms);

    const onShow = () => {
        if (document.visibilityState === 'visible' && missed) {
            missed = false;
            callback();
        }
    };

    document.addEventListener('visibilitychange', onShow);

    return () => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', onShow);
    };
}
