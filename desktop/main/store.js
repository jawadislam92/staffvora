'use strict';

const Store = require('electron-store');

const defaults = {
  apiBaseUrl: '',
  token: '',
  user: null,
  deviceName: '',
};

const store = new Store({
  name: 'tracksuite-desktop',
  defaults,
});

// Preserve settings for preview users upgrading from the pre-productized app.
// This migration is local-only and never transmits the stored credentials.
const legacy = new Store({ name: 'timetracker-desktop', defaults });
if (!store.get('legacySettingsImported')) {
  for (const key of Object.keys(defaults)) {
    const oldValue = legacy.get(key);
    const currentValue = store.get(key);
    if ((currentValue === '' || currentValue === null) && oldValue) {
      store.set(key, oldValue);
    }
  }
  store.set('legacySettingsImported', true);
}

module.exports = {
  get: (key) => store.get(key),
  set: (key, value) => store.set(key, value),
  delete: (key) => store.delete(key),
  clear: () => store.clear(),
  has: (key) => store.has(key),
  all: () => store.store,
};
