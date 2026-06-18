'use strict';

const Store = require('electron-store');

const store = new Store({
  name: 'timetracker-desktop',
  defaults: {
    apiBaseUrl: '',
    token: '',
    user: null,
    deviceName: '',
  },
});

module.exports = {
  get: (key) => store.get(key),
  set: (key, value) => store.set(key, value),
  delete: (key) => store.delete(key),
  clear: () => store.clear(),
  has: (key) => store.has(key),
  all: () => store.store,
};
