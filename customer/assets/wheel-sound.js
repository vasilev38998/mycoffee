(function(){
'use strict';
let audioContext=null,spinTimer=0,spinToken=0,spinActive=false,lastTick=0;
const AudioContextClass=window.AudioContext||window.webkitAudioContext;

function ensureAudio(){
  if(!AudioContextClass)return null;
  try{
    if(!audioContext||audioContext.state==='closed')audioContext=new AudioContextClass();
    if(audioContext.state==='suspended')audioContext.resume().catch(()=>{});
    return audioContext;
  }catch(e){return null}
}
function tone(frequency,duration,volume,type='triangle',delay=0){
  const ctx=ensureAudio();
  if(!ctx||ctx.state!=='running')return;
  const start=ctx.currentTime+Math.max(0,delay),end=start+duration;
  try{
    const osc=ctx.createOscillator(),gain=ctx.createGain();
    osc.type=type;
    osc.frequency.setValueAtTime(frequency,start);
    gain.gain.setValueAtTime(.0001,start);
    gain.gain.exponentialRampToValueAtTime(Math.max(.0002,volume),start+.004);
    gain.gain.exponentialRampToValueAtTime(.0001,end);
    osc.connect(gain);gain.connect(ctx.destination);
    osc.start(start);osc.stop(end+.01);
  }catch(e){}
}
function wheelTick(progress){
  const now=performance.now();
  if(now-lastTick<30)return;
  lastTick=now;
  const p=Math.max(0,Math.min(1,Number(progress)||0));
  const pitch=980-p*310+(Math.random()-.5)*62;
  const volume=.024-p*.004;
  tone(pitch,.022,volume,'square');
  tone(pitch*.51,.032,.009,'triangle',.002);
  if(p>.72&&Math.random()>.72)tone(170+p*35,.045,.006,'sine',.004);
}
function stopSpinTicks(){
  spinActive=false;
  spinToken++;
  clearTimeout(spinTimer);
  spinTimer=0;
}
function startSpinTicks(){
  if(spinActive)return;
  stopSpinTicks();
  spinActive=true;
  tone(156,.11,.026,'sine');
  tone(312,.075,.013,'triangle',.018);
  const token=spinToken,started=performance.now(),duration=5100;
  const step=()=>{
    if(!spinActive||token!==spinToken||document.hidden)return;
    const elapsed=performance.now()-started;
    if(elapsed>=duration){stopSpinTicks();return}
    const progress=Math.max(0,Math.min(1,elapsed/duration));
    wheelTick(progress);
    const interval=49+Math.pow(progress,2.2)*300;
    spinTimer=setTimeout(step,interval);
  };
  step();
}
function playPrizeSound(){
  stopSpinTicks();
  const ctx=ensureAudio();
  if(!ctx||ctx.state!=='running')return;
  tone(118,.13,.04,'sine');
  tone(236,.09,.022,'triangle',.01);
  const notes=[523.25,659.25,783.99,1046.5];
  notes.forEach((frequency,index)=>tone(frequency,.3,index===3?.047:.035,'sine',.07+index*.085));
  tone(1318.51,.19,.023,'triangle',.45);
  tone(1567.98,.24,.019,'triangle',.55);
}
function isWheelTransition(event){
  return event?.target?.id==='wheelDisc'&&event.propertyName==='transform';
}
function unlockFromSpin(event){
  const target=event.target instanceof Element?event.target.closest('#wheelSpinButton'):null;
  if(!target||target.disabled)return;
  ensureAudio();
}
function beginOnTransition(event){
  if(!isWheelTransition(event))return;
  if(!document.getElementById('wheelStage')?.classList.contains('spinning'))return;
  startSpinTicks();
}
function endOnTransition(event){if(isWheelTransition(event))stopSpinTicks()}

document.addEventListener('pointerdown',unlockFromSpin,true);
document.addEventListener('click',unlockFromSpin,true);
document.addEventListener('transitionrun',beginOnTransition,true);
document.addEventListener('transitionstart',beginOnTransition,true);
document.addEventListener('transitionend',endOnTransition,true);
document.addEventListener('transitioncancel',endOnTransition,true);
window.addEventListener('kapouch:wheel',playPrizeSound);
document.addEventListener('visibilitychange',()=>{if(document.hidden)stopSpinTicks()});
})();
