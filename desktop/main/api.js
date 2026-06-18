'use strict';

const axios = require('axios');
const fs = require('node:fs');
const { EventEmitter } = require('node:events');
const FormData = require('form-data');
const store = require('./store');
const { DEFAULTS } = require('./config');

// Emits 'expired' once when the server rejects our token, so the UI can drop
// to the login screen instead of showing raw 401 errors forever.
const authEvents = new EventEmitter();
let expiredNotified = false;

function handleAuthError(error) {
  if (error?.response?.status === 401 && store.get('token')) {
    store.delete('token');
    store.delete('user');
    if (!expiredNotified) {
      expiredNotified = true;
      authEvents.emit('expired');
    }
  }
  return Promise.reject(error);
}

function baseUrl() {
  return (store.get('apiBaseUrl') || DEFAULTS.apiBaseUrl).replace(/\/+$/, '');
}

function client() {
  const token = store.get('token');
  const instance = axios.create({
    baseURL: baseUrl() + '/api/desktop',
    timeout: 20000,
    headers: {
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  instance.interceptors.response.use((res) => res, handleAuthError);
  return instance;
}

async function login({ email, password, deviceName, apiBaseUrl }) {
  if (apiBaseUrl) {
    store.set('apiBaseUrl', apiBaseUrl.replace(/\/+$/, ''));
  }
  const url = baseUrl() + '/api/desktop/login';

  let res;
  try {
    res = await axios.post(url, {
      email,
      password,
      device_name: deviceName,
    }, { timeout: 20000, headers: { Accept: 'application/json' } });
  } catch (err) {
    // Turn raw Axios/HTTP failures into a clear, human message the login
    // screen can show — instead of "Request failed with status code 422".
    if (err.response) {
      const status = err.response.status;
      if (status === 422 || status === 401) {
        throw new Error('Incorrect email or password.');
      }
      if (status === 429) {
        throw new Error('Too many sign-in attempts. Please wait a minute and try again.');
      }
      throw new Error(err.response.data?.message || `Sign-in failed (server error ${status}). Please try again.`);
    }
    if (err.code === 'ECONNABORTED') {
      throw new Error('The server took too long to respond. Check your connection and try again.');
    }
    // No response at all — DNS/network/server down or a wrong server URL.
    throw new Error('Could not reach the server. Check your internet connection (and the Server URL under Advanced).');
  }

  store.set('token', res.data.token);
  store.set('user', res.data.user);
  store.set('deviceName', deviceName);
  expiredNotified = false;
  return res.data.user;
}

async function logout() {
  try {
    await client().post('/logout');
  } catch {
    // ignore; we wipe the local token anyway
  }
  store.delete('token');
  store.delete('user');
}

async function me() {
  const res = await client().get('/me');
  return res.data;
}

async function getClients() {
  const res = await client().get('/clients');
  return res.data.clients;
}

async function getWorkTypes() {
  const res = await client().get('/work-types');
  return res.data.work_types;
}

async function getUpworkProfiles() {
  const res = await client().get('/upwork-profiles');
  return res.data.profiles;
}

async function getSettings() {
  const res = await client().get('/settings');
  return res.data.settings;
}

async function startSession(payload) {
  const res = await client().post('/sessions/start', payload);
  return res.data;
}

async function heartbeat(sessionId, payload) {
  const res = await client().patch(`/sessions/${sessionId}/heartbeat`, payload);
  return res.data;
}

async function stopSession(sessionId, payload) {
  const res = await client().post(`/sessions/${sessionId}/stop`, payload);
  return res.data;
}

async function todaySessions() {
  const res = await client().get('/sessions/today');
  return res.data.sessions;
}

async function weekSummary() {
  const res = await client().get('/sessions/week');
  return res.data.days;
}

async function recentClients() {
  const res = await client().get('/sessions/recent-clients');
  return res.data.clients;
}

async function timeClockStatus() {
  const res = await client().get('/time-clock');
  return res.data;
}

async function timeClockAct(actionType) {
  try {
    const res = await client().post('/time-clock', { action_type: actionType });
    return res.data;
  } catch (err) {
    // Surface the server's human message through the IPC boundary (which
    // only carries Error.message).
    throw new Error(err.response?.data?.message || err.message);
  }
}

async function uploadScreenshot(localPath, payload) {
  const form = new FormData();
  form.append('tracking_session_id', String(payload.tracking_session_id));
  form.append('captured_at', payload.captured_at);
  if (payload.activity_percent != null) form.append('activity_percent', String(payload.activity_percent));
  if (payload.keyboard_count != null) form.append('keyboard_count', String(payload.keyboard_count));
  if (payload.mouse_count != null) form.append('mouse_count', String(payload.mouse_count));
  if (payload.active_app) form.append('active_app', payload.active_app);
  if (payload.active_window_title) form.append('active_window_title', payload.active_window_title);
  if (payload.url_domain) form.append('url_domain', payload.url_domain);
  form.append('image', fs.createReadStream(localPath));

  const token = store.get('token');
  const res = await axios.post(baseUrl() + '/api/desktop/screenshots', form, {
    timeout: 60000,
    maxBodyLength: Infinity,
    headers: {
      ...form.getHeaders(),
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  });
  return res.data;
}

async function sendActivityBatch(payload) {
  const res = await client().post('/activity/batch', payload);
  return res.data;
}

function isOnline(error) {
  // axios network error has no response; treat anything without response as offline
  return !!(error && error.response);
}

module.exports = {
  authEvents,
  baseUrl,
  login,
  logout,
  me,
  getClients,
  getWorkTypes,
  getUpworkProfiles,
  getSettings,
  startSession,
  heartbeat,
  stopSession,
  todaySessions,
  weekSummary,
  recentClients,
  timeClockStatus,
  timeClockAct,
  uploadScreenshot,
  sendActivityBatch,
  isOnline,
};
