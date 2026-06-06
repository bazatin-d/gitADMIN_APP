function toggleManualMode() {
    const mode = document.querySelector('input[name="manual_mode"]:checked').value;
    document.getElementById('manualAnswersBlock').classList.toggle('hidden', mode !== 'answers');
    document.getElementById('manualScoresBlock').classList.toggle('hidden', mode !== 'scores');
}

function focusQuestion(questionNumber) {
    const row = document.querySelector('.manual-answer-row[data-question="' + questionNumber + '"]');
    if (!row) return;
    row.scrollIntoView({block: 'nearest', behavior: 'smooth'});
    row.classList.add('ring-2', 'ring-[#ffa048]');
    setTimeout(() => row.classList.remove('ring-2', 'ring-[#ffa048]'), 450);
}

function clearManualAnswers() {
    document.querySelectorAll('#manualAnswersBlock input[type="radio"]').forEach(input => input.checked = false);
    focusQuestion(1);
}

document.addEventListener('keydown', function(e) {
    const mode = document.querySelector('input[name="manual_mode"]:checked')?.value;
    if (mode !== 'answers') return;
    if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName) && document.activeElement.type !== 'radio') return;
    if (!['1', '2', '3'].includes(e.key)) return;

    const rows = Array.from(document.querySelectorAll('.manual-answer-row'));
    let currentIndex = rows.findIndex(row => !row.querySelector('input[type="radio"]:checked'));
    if (currentIndex === -1) return;

    const value = e.key === '1' ? '+' : (e.key === '2' ? '?' : '-');
    const input = rows[currentIndex].querySelector('input[value="' + value + '"]');
    if (input) input.checked = true;
    focusQuestion(currentIndex + 2);
    e.preventDefault();
});

toggleManualMode();
