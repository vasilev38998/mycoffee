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
  const pitch=920-p*260+(Math.random()-.5)*55;
  tone(pitch,.024,.022,'square');
  tone(pitch*.52,.03,.009,'triangle',.002);
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
  const token=spinToken,started=performance.now(),duration=5100;
  const step=()=>{
    if(!spinActive||token!==spinToken||document.hidden)return;
    const elapsed=performance.now()-started;
    if(elapsed>=duration){stopSpinTicks();return}
    const progress=Math.max(0,Math.min(1,elapsed/duration));
    wheelTick(progress);
    const interval=54+Math.pow(progress,2.25)*285;
    spinTimer=setTimeout(step,interval);
  };
  step();
}
function playPrizeSound(){
  stopSpinTicks();
  const ctx=ensureAudio();
  if(!ctx||ctx.state!=='running')return;
  const notes=[523.25,659.25,783.99,1046.5];
  notes.forEach((frequency,index)=>tone(frequency,.28,index===3?.045:.035,'sine',index*.085));
  tone(1318.51,.18,.022,'triangle',.38);
  tone(1567.98,.22,.018,'triangle',.48);
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
