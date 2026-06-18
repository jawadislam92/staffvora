'use strict';

const { contextBridge, ipcRenderer } = require('electron');

const api = {
  auth: {
    login: (payload) => ipcRenderer.invoke('auth:login', payload),
    logout: () => ipcRenderer.invoke('auth:logout'),
    me: () => ipcRenderer.invoke('auth:me'),
    state: () => ipcRenderer.invoke('auth:state'),
    saved: () => ipcRenderer.invoke('auth:saved'),
    onExpired: (cb) => {
      const listener = () => cb();
      ipcRenderer.on('auth:expired', listener);
      return () => ipcRenderer.removeListener('auth:expired', listener);
    },
  },
  updates: {
    check: () => ipcRenderer.invoke('updates:check'),
    install: () => ipcRenderer.invoke('updates:install'),
    onEvent: (cb) => {
      const listener = (_e, payload) => cb(payload);
      ipcRenderer.on('updates:event', listener);
      return () => ipcRenderer.removeListener('updates:event', listener);
    },
  },
  settings: {
    setApiBaseUrl: (url) => ipcRenderer.invoke('settings:apiBaseUrl:set', url),
    getAutoLaunch: () => ipcRenderer.invoke('settings:autoLaunch:get'),
    setAutoLaunch: (enabled) => ipcRenderer.invoke('settings:autoLaunch:set', enabled),
    getPrefs: () => ipcRenderer.invoke('settings:prefs:get'),
    setPrefs: (patch) => ipcRenderer.invoke('settings:prefs:set', patch),
  },
  appInfo: {
    version: () => ipcRenderer.invoke('app:version'),
  },
  timeclock: {
    status: () => ipcRenderer.invoke('timeclock:status'),
    act: (actionType) => ipcRenderer.invoke('timeclock:act', actionType),
  },
  meta: {
    clients: () => ipcRenderer.invoke('meta:clients'),
    workTypes: () => ipcRenderer.invoke('meta:workTypes'),
    upworkProfiles: () => ipcRenderer.invoke('meta:upworkProfiles'),
    settings: () => ipcRenderer.invoke('meta:settings'),
    todaySessions: () => ipcRenderer.invoke('meta:todaySessions'),
    weekSummary: () => ipcRenderer.invoke('meta:weekSummary'),
    recentClients: () => ipcRenderer.invoke('meta:recentClients'),
  },
  deeplink: {
    onOpen: (cb) => {
      const listener = (_e, payload) => cb(payload);
      ipcRenderer.on('deeplink', listener);
      return () => ipcRenderer.removeListener('deeplink', listener);
    },
  },
  tracker: {
    start: (opts) => ipcRenderer.invoke('tracker:start', opts),
    stop: (opts) => ipcRenderer.invoke('tracker:stop', opts),
    status: () => ipcRenderer.invoke('tracker:status'),
    onChanged: (cb) => {
      const listener = (_e, status) => cb(status);
      ipcRenderer.on('tracker:changed', listener);
      return () => ipcRenderer.removeListener('tracker:changed', listener);
    },
    onStopped: (cb) => {
      const listener = (_e, session) => cb(session);
      ipcRenderer.on('tracker:stopped', listener);
      return () => ipcRenderer.removeListener('tracker:stopped', listener);
    },
    onWarning: (cb) => {
      const listener = (_e, message) => cb(message);
      ipcRenderer.on('tracker:warning', listener);
      return () => ipcRenderer.removeListener('tracker:warning', listener);
    },
  },
};

contextBridge.exposeInMainWorld('tt', api);
