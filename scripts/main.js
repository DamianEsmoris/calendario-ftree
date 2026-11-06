const MONTH_NAMES = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
];

const calendarTable = document.getElementById('calendarTable');
const recordsList = document.getElementById('recordsList');
const selectedDayLabel = document.getElementById('selectedDayLabel');
const dateField = document.getElementById('dateField');

const editDialog = {
    dialog: document.getElementById('editDialog'),
    epochField: document.getElementById('editEpochField'),
    recordIdxField: document.getElementById('editRecordIdxField'),
    eventField: document.getElementById('editEventField'),
    cancelBtn: document.getElementById('editCancelBtn'),
};

const deleteDialog = {
    dialog: document.getElementById('deleteDialog'),
    epochField: document.getElementById('deleteEpochField'),
    recordIdxField: document.getElementById('deleteRecordIdxField'),
    eventLabel: document.getElementById('deleteEventLabel'),
    cancelBtn: document.getElementById('deleteCancelBtn'),
};

const {month, year} = getUrlDateOrCUrrentDate();

const [firstDayOfMonth, lastDayOfMonth] = getMonthBounds();

const dayCells = {};
let days = [];

setCalendarTitle(MONTH_NAMES[firstDayOfMonth.getMonth()] + ' ' + firstDayOfMonth.getFullYear());
updateNavegationUrls(firstDayOfMonth);
generateDayCells();
fetchDays();
setupAddEventForm();
setupDialogs();

//

function getUrlDateOrCUrrentDate() {
    const params = new URLSearchParams(window.location.search);
    const now = new Date();
    const month = params.has('month') ? parseInt(params.get('month'), 10)-1 : now.getMonth();
    const year = params.has('year') ? parseInt(params.get('year'), 10) : now.getFullYear();
    return {month, year};
}

function getMonthBounds() {
    const first = new Date(year, month, 1, 0, 0 ,0);
    const last = new Date(first);
    last.setMonth(last.getMonth()+1);
    last.setDate(0);
    return [first, last];
}

function setCalendarTitle(title) {
    const calendarTitle = calendarTable.querySelector('#calendarTitle');
    calendarTitle.textContent = title;
}

function updateNavegationUrls(date) {
    const prevAnchor = calendarTable.querySelector('#prevMonthArrow');
    const nextAnchor = calendarTable.querySelector('#nextMonthArrow');

    const nextMonth = new Date(date);
    nextMonth.setMonth(nextMonth.getMonth() + 1);

    const prevMonth = new Date(date);
    prevMonth.setMonth(prevMonth.getMonth() - 1);

    const setAnchorTargetDateHref = (anchor, targetDate) => {
        anchor.href = `?month=${targetDate.getMonth()+1}&year=${targetDate.getFullYear()}`;
    }

    setAnchorTargetDateHref(prevAnchor, prevMonth);
    setAnchorTargetDateHref(nextAnchor, nextMonth);
}

function startOfToday() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return today;
}

function generateDayCells() {
    const d = new Date(firstDayOfMonth);
    const currentMonth = d.getMonth();
    const calendarBody = calendarTable.querySelector('tbody')
    const todayEpoch = Math.floor(startOfToday().getTime() / 1000);

    let row = document.createElement('tr');
    let td;

    for (let i = 0; i< d.getDay(); i++)
        row.appendChild(document.createElement('td'));

    do {
        if (d.getDay() === 0) {
            calendarBody.appendChild(row);
            row = document.createElement('tr');
        }
        td = document.createElement('td');
        td.textContent = d.getDate();

        const epoch = Math.floor(d.getTime() / 1000);
        dayCells[epoch] = td;
        if (epoch === todayEpoch) {
            td.classList.add('today');
        }
        td.onclick = () => loadAside(epoch);

        row.appendChild(td);

        d.setDate(d.getDate() + 1);
    } while (d.getMonth() === currentMonth);

    calendarBody.appendChild(row);

}

async function fetchDays() {
    const response = await fetchCalendar();
    days = await response.json();

    for (const {epoch} of days) {
        dayCells[epoch]?.classList.add('has-events');
    }

    const todayEpoch = Math.floor(startOfToday().getTime() / 1000);
    if (dayCells[todayEpoch]) {
        loadAside(todayEpoch);
    }
}

function fetchCalendar() {
    const fromEpoch = Math.floor(firstDayOfMonth.getTime() / 1000);
    const untilEpoch = Math.floor(lastDayOfMonth.getTime() / 1000);
    return fetch(`calendar.php?fromEpoch=${fromEpoch}&untilEpoch=${untilEpoch}`);
}

function setupAddEventForm() {
    const form = document.getElementById('addEventForm');
    const epochField = document.getElementById('epochField');

    form.onsubmit = () => {
        const [y, m, d] = dateField.value.split('-').map(Number);
        const date = new Date(y, m - 1, d).getTime() / 1000;
        epochField.value = Math.floor(date);
    };
}

function loadAside(selectedEpoch) {
    [...recordsList.children].forEach(elem => elem.remove());

    const selectedDate = new Date(selectedEpoch * 1000);
    selectedDayLabel.textContent = `${selectedDate.getDate()} de ${MONTH_NAMES[selectedDate.getMonth()]}`;
    const pad = (n) => String(n).padStart(2, '0');
    dateField.value = `${selectedDate.getFullYear()}-${pad(selectedDate.getMonth() + 1)}-${pad(selectedDate.getDate())}`;

    const day = days.find(({epoch}) => epoch === selectedEpoch);
    if (!day || !day.records || !day.records.length) {
        const li = document.createElement('li');
        li.textContent = 'Sin eventos';
        li.classList.add('empty');
        recordsList.appendChild(li);
        return;
    }

    day.records.forEach((record, recordIdx) => {
        const li = document.createElement('li');

        const text = document.createElement('span');
        text.textContent = record;
        li.appendChild(text);

        const actions = document.createElement('span');
        actions.className = 'record-actions';

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.textContent = 'Editar';
        editBtn.onclick = () => openEditDialog(selectedEpoch, recordIdx, record);
        actions.appendChild(editBtn);

        const deleteBtn = document.createElement('button');
        deleteBtn.type = 'button';
        deleteBtn.textContent = 'Borrar';
        deleteBtn.onclick = () => openDeleteDialog(selectedEpoch, recordIdx, record);
        actions.appendChild(deleteBtn);

        li.appendChild(actions);
        recordsList.appendChild(li);
    });

}

function openEditDialog(epoch, recordIdx, currentText) {
    editDialog.epochField.value = epoch;
    editDialog.recordIdxField.value = recordIdx;
    editDialog.eventField.value = currentText;
    editDialog.dialog.showModal();
}

function openDeleteDialog(epoch, recordIdx, text) {
    deleteDialog.epochField.value = epoch;
    deleteDialog.recordIdxField.value = recordIdx;
    deleteDialog.eventLabel.textContent = text;
    deleteDialog.dialog.showModal();
}

function setupDialogs() {
    editDialog.cancelBtn.onclick = () => editDialog.dialog.close();
    deleteDialog.cancelBtn.onclick = () => deleteDialog.dialog.close();

    for (const {dialog} of [editDialog, deleteDialog]) {
        dialog.onclick = (e) => {
            if (e.target === dialog) dialog.close();
        }
    }
}
