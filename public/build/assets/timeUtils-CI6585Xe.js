import{c,z as s,R as n}from"./app-CEwOfoZ4.js";import{C as d}from"./circle-x-CDTX3tHx.js";import{e as u,L as h}from"./AuthenticatedLayout-D0vmN8jh.js";/**
 * @license lucide-react v0.532.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const y=[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["line",{x1:"12",x2:"12",y1:"8",y2:"12",key:"1pkeuh"}],["line",{x1:"12",x2:"12.01",y1:"16",y2:"16",key:"4dfq90"}]],f=c("circle-alert",y);/**
 * @license lucide-react v0.532.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const k=[["path",{d:"M21.801 10A10 10 0 1 1 17 3.335",key:"yps3ct"}],["path",{d:"m9 11 3 3L22 4",key:"1pflzl"}]],l=c("circle-check-big",k);/**
 * @license lucide-react v0.532.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const p=[["path",{d:"M9 9.003a1 1 0 0 1 1.517-.859l4.997 2.997a1 1 0 0 1 0 1.718l-4.997 2.997A1 1 0 0 1 9 14.996z",key:"kmsa83"}],["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}]],$=c("circle-play",p);/**
 * @license lucide-react v0.532.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const b=[["path",{d:"M10 2v2",key:"7u0qdc"}],["path",{d:"M14 2v2",key:"6buw04"}],["path",{d:"M16 8a1 1 0 0 1 1 1v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1h14a4 4 0 1 1 0 8h-1",key:"pwadti"}],["path",{d:"M6 2v2",key:"colzsn"}]],g=c("coffee",b);/**
 * @license lucide-react v0.532.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const m=[["path",{d:"m10 17 5-5-5-5",key:"1bsop3"}],["path",{d:"M15 12H3",key:"6jk70r"}],["path",{d:"M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4",key:"u53s6r"}]],x=c("log-in",m);/**
 * @license lucide-react v0.532.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const _=[["path",{d:"M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8",key:"1357e3"}],["path",{d:"M3 3v5h5",key:"1xhq8a"}]],v=c("rotate-ccw",_);/**
 * @license lucide-react v0.532.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const C=[["rect",{width:"18",height:"18",x:"3",y:"3",rx:"2",key:"afitv7"}]],S=c("square",C),a={borderRadius:"12px",background:"#ffffff",color:"#1f2937",border:"1px solid #e5e7eb",padding:"16px",fontSize:"14px",fontWeight:"500",boxShadow:"0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)"},i={width:"20px",height:"20px",marginRight:"8px"},N=(o,e=null)=>{const t={clock_in:{message:e||"Successfully clocked in! Ready to be productive? 💪",icon:x,color:"#10b981"},clock_out:{message:e||"Successfully clocked out! Great work today! 🎉",icon:h,color:"#ef4444"},break_start:{message:e||"Break started! Take your time to recharge ☕",icon:g,color:"#f59e0b"},break_end:{message:e||"Welcome back! Ready to continue? 🔄",icon:u,color:"#3b82f6"}}[o]||{message:e||"Action completed successfully!",icon:l,color:"#10b981"};s.success(t.message,{style:{...a,borderColor:t.color,borderLeftWidth:"4px"},icon:n.createElement(t.icon,{style:{...i,color:t.color}}),duration:4e3})},R=o=>{s.error(o,{style:{...a,borderColor:"#ef4444",borderLeftWidth:"4px"},icon:n.createElement(d,{style:{...i,color:"#ef4444"}}),duration:5e3})},W=(o,e)=>{const t=`${{clock_in:"Clock In",clock_out:"Clock Out",break_start:"Start Break",break_end:"End Break"}[o]||o} is not available. ${e}`;s(t,{style:{...a,borderColor:"#6b7280",borderLeftWidth:"4px"},icon:n.createElement(f,{style:{...i,color:"#6b7280"}}),duration:3e3})},E=(o="Processing...")=>s.loading(o,{style:{...a,borderColor:"#6b7280",borderLeftWidth:"4px"}}),z=()=>{s.success("📊 Time entries exported successfully! Check your downloads folder.",{style:{...a,borderColor:"#10b981",borderLeftWidth:"4px"},icon:n.createElement(l,{style:{...i,color:"#10b981"}}),duration:5e3})},A=o=>{const e=Math.max(0,Number(o)||0);if(e===0)return"0m";const r=Math.floor(e),t=Math.round((e-r)*60);return r===0?`${t}m`:t===0?`${r}h`:`${r}h ${t}m`},B=()=>{const o=new Date().getHours();return o<5?"Working late night? 🌙":o<12?"Good morning! ☀️":o<17?"Good afternoon! 🌤️":o<20?"Good evening! 🌅":"Good evening! 🌙"};export{g as C,v as R,S,$ as a,W as b,E as c,N as d,z as e,A as f,B as g,R as s};
