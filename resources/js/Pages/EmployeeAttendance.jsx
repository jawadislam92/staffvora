import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    CalendarDays,
    CalendarRange,
    ChevronDown,
    ChevronUp,
    Clock,
    Coffee,
    Download,
    History,
    List,
    LogOut,
    PauseCircle,
    Pencil,
    PlayCircle,
    RotateCcw,
    Search,
    Send,
    Square,
    Timer,
    Users,
    X,
} from 'lucide-react';
import axios from 'axios';
import toast from 'react-hot-toast';
import {
    Badge,
    Button,
    Card,
    Checkbox,
    EmptyState,
    Field,
    Input,
    Select,
    Skeleton,
    KpiCard,
    Table,
    TBody,
    Td,
    THead,
    Th,
    Tr,
    Tabs,
} from '@/Components/ui';
import Modal from '@/Components/Modal';
import { showError, showExportSuccess, showLoading } from '@/Utils/notifications';
import { formatHours } from '@/Utils/timeUtils';

const cx = (...a) => a.filter(Boolean).join(' ');

const TABS = [
    { id: 'monthly', label: 'Monthly Grid', icon: CalendarDays },
    { id: 'summary', label: 'Summary', icon: Activity },
    { id: 'detailed', label: 'Activity', icon: List },
    { id: 'timeline', label: 'Timeline', icon: Timer },
];

const STATUS_OPTIONS = ['all', 'Working', 'On Break', 'Clocked Out', 'Not Started'];

const attendanceSlackFieldOptions = [
    { value: 'present', label: 'Present' },
    { value: 'absent', label: 'Absent' },
    { value: 'leave', label: 'Leave' },
    { value: 'half_day', label: 'Half day' },
    { value: 'work_from_home', label: 'WFH' },
    { value: 'late_coming', label: 'Late coming' },
    { value: 'late_joining', label: 'Late joining' },
    { value: 'holidays', label: 'Holidays' },
    { value: 'public_holiday', label: 'Public holiday' },
    { value: 'total_work_hours', label: 'Hours' },
];

const calendarStatusOptions = [
    { value: 'L', label: 'Leave' },
    { value: 'H', label: 'Holiday' },
    { value: 'PH', label: 'Public holiday' },
    { value: 'WFH', label: 'Work from home' },
    { value: 'HD', label: 'Half day' },
];

// Live-status pill → semantic Badge tone (summary tab).
const LIVE_STATUS_TONE = {
    Working: 'success',
    'On Break': 'warning',
    'Clocked Out': 'danger',
    'Not Started': 'neutral',
};

// Attendance code → grid chip classes, built ONLY from semantic status tokens.
// Present=success, Absent=danger, Late=warning, Leave=info, Off/holiday=surface-2,
// WFH / public holiday lean on the electric-blue accent. The letter code + the
// legend keep reused tones unambiguous.
const GRID_TONE = {
    P: 'bg-success-soft text-success',
    LC: 'bg-warning-soft text-warning',
    A: 'bg-danger-soft text-danger',
    L: 'bg-info-soft text-info',
    HD: 'bg-warning-soft text-warning',
    WFH: 'bg-accent/10 text-accent',
    H: 'bg-surface-2 text-muted',
    PH: 'bg-accent/10 text-accent',
    LI: 'bg-surface-2 text-subtle',
    empty: 'bg-app text-subtle',
};

// Code → meaning for the monthly-grid legend (so the colored codes are readable).
const gridLegend = [
    ['P', 'Present'],
    ['A', 'Absent'],
    ['LC', 'Late coming'],
    ['LI', 'Before joining'],
    ['L', 'Leave'],
    ['H', 'Weekly off / holiday'],
    ['PH', 'Public holiday'],
    ['WFH', 'Work from home'],
    ['HD', 'Half day'],
];

const actionMeta = (actionType) => {
    if (actionType === 'clock_in') return { label: 'Clocked In', icon: PlayCircle, tone: 'bg-success-soft text-success' };
    if (actionType === 'clock_out') return { label: 'Clocked Out', icon: Square, tone: 'bg-danger-soft text-danger' };
    if (actionType === 'break_start') return { label: 'Break Started', icon: PauseCircle, tone: 'bg-warning-soft text-warning' };
    if (actionType === 'break_end') return { label: 'Break Ended', icon: RotateCcw, tone: 'bg-info-soft text-info' };
    return { label: actionType, icon: Clock, tone: 'bg-surface-2 text-muted' };
};

// ---------------------------------------------------------------------------
// DEV_SAMPLE — used ONLY when `preview` is true. Lets the whole attendance page
// render fully offline (no auth, no backend) for design review / storybook-style
// previews. NONE of this is ever fetched or shown to a real authenticated user.
// ---------------------------------------------------------------------------
const PREVIEW_MONTH = '2026-07';
const PREVIEW_TODAY_DAY = 22;

function buildPreviewMonthlyGrid() {
    const [year, month] = PREVIEW_MONTH.split('-').map(Number);
    const daysInMonth = new Date(year, month, 0).getDate();
    const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const LABELS = {
        P: 'Present', A: 'Absent', H: 'Weekly off', L: 'Leave', HD: 'Half day',
        WFH: 'Work from home', LC: 'Late coming', LI: 'Before joining', PH: 'Public holiday',
    };
    const WORKED = new Set(['P', 'LC', 'WFH']);
    const PUBLIC_HOLIDAYS = new Set([14]); // Jul 14 — company-wide holiday

    const days = [];
    for (let d = 1; d <= daysInMonth; d += 1) {
        const dow = new Date(Date.UTC(year, month - 1, d)).getUTCDay();
        days.push({
            date: `${PREVIEW_MONTH}-${String(d).padStart(2, '0')}`,
            day: d,
            weekday: WD[dow],
            is_weekend: dow === 0,
        });
    }

    const roster = [
        { user_id: 1, user_name: 'Aqsa Khan', designation: 'Frontend Engineer', joinDay: 1, overrides: { 3: 'LC', 10: 'L', 17: 'WFH' } },
        { user_id: 2, user_name: 'Musa Raza', designation: 'Backend Engineer', joinDay: 1, overrides: { 4: 'A', 11: 'WFH', 18: 'LC' } },
        { user_id: 3, user_name: 'Sana Faisal', designation: 'Product Designer', joinDay: 1, overrides: { 7: 'HD', 15: 'L', 21: 'LC' } },
        { user_id: 4, user_name: 'Hamza Zaidi', designation: 'QA Analyst', joinDay: 1, overrides: { 2: 'A', 9: 'WFH', 16: 'HD', 22: 'LC' } },
        { user_id: 5, user_name: 'Nida Amin', designation: 'Marketing Lead', joinDay: 3, overrides: { 8: 'L', 20: 'A' } },
        { user_id: 6, user_name: 'Bilal Tariq', designation: 'DevOps Engineer', joinDay: 1, overrides: { 5: 'WFH', 12: 'HD', 19: 'A' } },
    ];

    const employees = roster.map((person) => {
        const summary = {
            holidays: 0, present: 0, absent: 0, leave: 0, half_day: 0,
            work_from_home: 0, late_coming: 0, late_joining: 0, public_holiday: 0, total_work_hours: 0,
        };

        const dayCells = days.map((day) => {
            const d = day.day;
            let code = null;
            let source = 'automatic';

            if (d < person.joinDay) {
                code = 'LI';
            } else if (person.overrides[d]) {
                code = person.overrides[d];
                source = 'manual';
            } else if (PUBLIC_HOLIDAYS.has(d)) {
                code = 'PH';
            } else if (day.is_weekend) {
                code = 'H';
            } else if (d > PREVIEW_TODAY_DAY) {
                code = null;
                source = 'future';
            } else {
                code = 'P';
            }

            const isWorked = WORKED.has(code);
            const workHours = code === 'HD' ? 4 : isWorked ? Math.round((7.3 + (d % 4) * 0.22) * 100) / 100 : 0;

            summary.total_work_hours += workHours;
            if (code === 'LC') { summary.present += 1; summary.late_coming += 1; }
            else if (code === 'LI') summary.late_joining += 1;
            else if (code === 'P') summary.present += 1;
            else if (code === 'A') summary.absent += 1;
            else if (code === 'H') summary.holidays += 1;
            else if (code === 'L') summary.leave += 1;
            else if (code === 'HD') summary.half_day += 1;
            else if (code === 'WFH') summary.work_from_home += 1;
            else if (code === 'PH') summary.public_holiday += 1;

            return {
                date: day.date,
                status_code: code,
                status_label: code ? LABELS[code] : 'Not set',
                status_reason: null,
                source: code === null ? 'future' : source,
                work_hours: workHours,
                break_hours: isWorked ? 0.5 : 0,
                first_clock_in: isWorked ? (code === 'LC' ? '10:18 AM' : `09:0${d % 6} AM`) : null,
                last_action_time: isWorked ? `06:0${d % 6} PM` : null,
                manual_note: source === 'manual' ? 'Marked by admin' : null,
            };
        });

        summary.total_work_hours = Math.round(summary.total_work_hours * 100) / 100;

        return {
            user_id: person.user_id,
            user_name: person.user_name,
            avatar: null,
            designation: person.designation,
            days: dayCells,
            summary,
        };
    });

    return {
        month: PREVIEW_MONTH,
        days,
        employees,
        statusOptions: [
            { code: 'P', label: 'Present' }, { code: 'A', label: 'Absent' }, { code: 'H', label: 'Holiday' },
            { code: 'L', label: 'Leave' }, { code: 'HD', label: 'Half day' }, { code: 'WFH', label: 'Work from home' },
            { code: 'LC', label: 'Late coming' }, { code: 'LI', label: 'Late joining' }, { code: 'PH', label: 'Public holiday' },
        ],
        canManualMark: true,
    };
}

const DEV_SAMPLE = {
    user: { id: 0, name: 'Jawad', email: 'jawad@staffvora.com', role_label: 'Owner', is_super_admin: true, permissions: [] },
    monthly: buildPreviewMonthlyGrid(),
    summary: [
        { user_id: 1, user_name: 'Aqsa Khan', designation: 'Frontend Engineer', avatar: null, total_work_hours: 7.2, total_break_hours: 0.5, current_status: 'Working', first_clock_in: '09:02 AM', last_action_time: '04:30 PM', total_entries: 6 },
        { user_id: 2, user_name: 'Musa Raza', designation: 'Backend Engineer', avatar: null, total_work_hours: 6.8, total_break_hours: 0.6, current_status: 'Working', first_clock_in: '09:11 AM', last_action_time: '04:22 PM', total_entries: 5 },
        { user_id: 3, user_name: 'Sana Faisal', designation: 'Product Designer', avatar: null, total_work_hours: 5.5, total_break_hours: 0.8, current_status: 'On Break', first_clock_in: '09:34 AM', last_action_time: '02:15 PM', total_entries: 7 },
        { user_id: 4, user_name: 'Hamza Zaidi', designation: 'QA Analyst', avatar: null, total_work_hours: 4.92, total_break_hours: 0.4, current_status: 'Working', first_clock_in: '10:18 AM', last_action_time: '03:48 PM', total_entries: 4 },
        { user_id: 5, user_name: 'Nida Amin', designation: 'Marketing Lead', avatar: null, total_work_hours: 3.08, total_break_hours: 0.7, current_status: 'Clocked Out', first_clock_in: '09:05 AM', last_action_time: '12:40 PM', total_entries: 4 },
        { user_id: 6, user_name: 'Bilal Tariq', designation: 'DevOps Engineer', avatar: null, total_work_hours: 2.33, total_break_hours: 0.5, current_status: 'Clocked Out', first_clock_in: '08:58 AM', last_action_time: '11:20 AM', total_entries: 3 },
    ],
    detailed: [
        {
            user_id: 1, user_name: 'Aqsa Khan', designation: 'Frontend Engineer', avatar: null,
            entries: [
                { id: 11, action_type: 'clock_in', formatted_time: '09:02 AM', notes: null },
                { id: 12, action_type: 'break_start', formatted_time: '12:30 PM', notes: 'Lunch' },
                { id: 13, action_type: 'break_end', formatted_time: '01:05 PM', notes: null },
                { id: 14, action_type: 'clock_out', formatted_time: '06:04 PM', notes: 'EOD' },
            ],
        },
        {
            user_id: 3, user_name: 'Sana Faisal', designation: 'Product Designer', avatar: null,
            entries: [
                { id: 31, action_type: 'clock_in', formatted_time: '09:34 AM', notes: null },
                { id: 32, action_type: 'break_start', formatted_time: '01:00 PM', notes: null },
                { id: 33, action_type: 'break_end', formatted_time: '01:40 PM', notes: 'Back' },
            ],
        },
        {
            user_id: 4, user_name: 'Hamza Zaidi', designation: 'QA Analyst', avatar: null,
            entries: [
                { id: 41, action_type: 'clock_in', formatted_time: '10:18 AM', notes: 'Late - traffic' },
                { id: 42, action_type: 'clock_out', formatted_time: '03:48 PM', notes: null },
            ],
        },
    ],
    timeline: [
        {
            user_id: 1, user_name: 'Aqsa Khan', designation: 'Frontend Engineer', avatar: null,
            total_work_hours: 7.2, total_break_hours: 0.6,
            sessions: [
                { type: 'work', start_time: '09:02 AM', end_time: '12:30 PM', duration: '3h 28m' },
                { type: 'break', start_time: '12:30 PM', end_time: '01:05 PM', duration: '35m' },
                { type: 'work', start_time: '01:05 PM', end_time: '06:04 PM', duration: '4h 59m' },
            ],
        },
        {
            user_id: 2, user_name: 'Musa Raza', designation: 'Backend Engineer', avatar: null,
            total_work_hours: 6.8, total_break_hours: 0.6,
            sessions: [
                { type: 'work', start_time: '09:11 AM', end_time: '01:00 PM', duration: '3h 49m' },
                { type: 'break', start_time: '01:00 PM', end_time: '01:36 PM', duration: '36m' },
                { type: 'work', start_time: '01:36 PM', end_time: null, duration: '2h 46m (ongoing)' },
            ],
        },
    ],
    // One employee-day drill-in, as /employee-attendance/day-entries returns it.
    // `is_clocked_in` is true so the force-clock-out panel is visible in preview.
    day: {
        clock_in: '09:02',
        clock_out: '',
        break_start: '12:30',
        break_end: '13:05',
        timezone: 'Asia/Karachi',
        timezone_label: '',
        current_state: 'break_end',
        is_clocked_in: true,
        entries: [
            { id: 11, action_type: 'clock_in', time: '9:02 AM', notes: null },
            { id: 12, action_type: 'break_start', time: '12:30 PM', notes: 'Lunch' },
            { id: 13, action_type: 'break_end', time: '1:05 PM', notes: null },
        ],
    },
    history: [
        { id: 1, attendance_date: '2026-07-17', employee_name: 'Aqsa Khan', employee_designation: 'Frontend Engineer', old_status_code: null, old_status_label: 'Auto', new_status_code: 'WFH', new_status_label: 'Work from home', changed_by: 'Jawad', changed_at: '2026-07-17 9:12 AM', reason: 'Remote day approved' },
        { id: 2, attendance_date: '2026-07-15', employee_name: 'Sana Faisal', employee_designation: 'Product Designer', old_status_code: null, old_status_label: 'Auto', new_status_code: 'L', new_status_label: 'Leave', changed_by: 'Jawad', changed_at: '2026-07-15 8:47 AM', reason: 'Sick leave' },
        { id: 3, attendance_date: '2026-07-12', employee_name: 'Bilal Tariq', employee_designation: 'DevOps Engineer', old_status_code: null, old_status_label: 'Auto', new_status_code: 'HD', new_status_label: 'Half day', changed_by: 'Jawad', changed_at: '2026-07-12 2:05 PM', reason: 'Left early' },
        { id: 4, attendance_date: '2026-07-11', employee_name: 'Musa Raza', employee_designation: 'Backend Engineer', old_status_code: null, old_status_label: 'Auto', new_status_code: 'WFH', new_status_label: 'Work from home', changed_by: 'Jawad', changed_at: '2026-07-11 9:30 AM', reason: null },
        { id: 5, attendance_date: '2026-07-08', employee_name: 'Nida Amin', employee_designation: 'Marketing Lead', old_status_code: 'A', old_status_label: 'Absent', new_status_code: 'L', new_status_label: 'Leave', changed_by: 'Jawad', changed_at: '2026-07-08 10:02 AM', reason: 'Approved leave' },
    ],
};

// Follow the app-wide theme (data-theme on <html>) so native date/month pickers
// render their calendar chrome correctly in dark, light and black themes.
function useColorScheme() {
    const [scheme, setScheme] = useState(() => (
        typeof document !== 'undefined' && document.documentElement.getAttribute('data-theme') === 'light'
            ? 'light'
            : 'dark'
    ));

    useEffect(() => {
        if (typeof document === 'undefined') return undefined;
        const root = document.documentElement;
        const read = () => setScheme(root.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
        read();
        const observer = new MutationObserver(read);
        observer.observe(root, { attributes: true, attributeFilter: ['data-theme'] });
        return () => observer.disconnect();
    }, []);

    return scheme;
}

const EmployeeIdentity = ({ employee, size = 'small' }) => {
    const [imageFailed, setImageFailed] = useState(false);
    const avatarSize = size === 'large' ? 'h-11 w-11' : 'h-9 w-9';

    useEffect(() => {
        setImageFailed(false);
    }, [employee.avatar]);

    return (
        <div className="flex min-w-0 items-center gap-3">
            {employee.avatar && !imageFailed ? (
                <img
                    src={employee.avatar}
                    alt=""
                    className={`${avatarSize} shrink-0 rounded-full object-cover`}
                    onError={() => setImageFailed(true)}
                />
            ) : (
                <span className={`flex ${avatarSize} shrink-0 items-center justify-center rounded-full bg-surface-2 text-sm font-semibold text-body`}>
                    {employee.user_name.charAt(0).toUpperCase()}
                </span>
            )}
            <div className="min-w-0">
                <div className="truncate text-sm font-semibold text-body">{employee.user_name}</div>
                <div className="truncate text-xs text-muted">{employee.designation || 'Member'}</div>
            </div>
        </div>
    );
};

export default function EmployeeAttendance({
    auth,
    serverDate,
    canManuallyMarkAttendance = false,
    canEditClockTimes = false,
    canSendAttendanceSlack = false,
    slackConfigured = false,
    hasAnyAttendance = true,
    preview = false,
}) {
    const colorScheme = useColorScheme();

    // In preview mode we synthesize the auth user so the page renders with no
    // backend/session at all.
    const user = preview ? DEV_SAMPLE.user : auth?.user;
    const canExport = preview || user?.is_super_admin || user?.permissions?.includes('attendance.export');
    const canSendSlack = preview || canSendAttendanceSlack || user?.is_super_admin || user?.permissions?.includes('reports.send_slack');
    // Rewriting someone's recorded punches is its own permission (attendance.edit_times).
    const canEditTimes = preview || canEditClockTimes || user?.is_super_admin || user?.permissions?.includes('attendance.edit_times');
    const effectiveSlackConfigured = preview ? true : slackConfigured;

    const [activeTab, setActiveTab] = useState('monthly');
    const [employeesData, setEmployeesData] = useState(preview ? DEV_SAMPLE.summary : []);
    const [detailedActivityData, setDetailedActivityData] = useState(preview ? DEV_SAMPLE.detailed : []);
    const [timelineData, setTimelineData] = useState(preview ? DEV_SAMPLE.timeline : []);
    const [monthlyGridData, setMonthlyGridData] = useState(
        preview ? DEV_SAMPLE.monthly : { days: [], employees: [], statusOptions: [], canManualMark: false }
    );
    const [loading, setLoading] = useState(false);
    const [markingCell, setMarkingCell] = useState(null);
    const [showSlackDialog, setShowSlackDialog] = useState(false);
    const [isSendingSlack, setIsSendingSlack] = useState(false);
    const [slackUserIds, setSlackUserIds] = useState([]);
    const [slackFields, setSlackFields] = useState(attendanceSlackFieldOptions.map((field) => field.value));
    const [showHistoryDialog, setShowHistoryDialog] = useState(false);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [manualHistory, setManualHistory] = useState(preview ? DEV_SAMPLE.history : []);
    const [selectedDate, setSelectedDate] = useState(serverDate || new Date().toISOString().split('T')[0]);
    const [selectedMonth, setSelectedMonth] = useState((serverDate || new Date().toISOString().split('T')[0]).slice(0, 7));
    const [showCalendarDialog, setShowCalendarDialog] = useState(false);
    const [isUpdatingCalendar, setIsUpdatingCalendar] = useState(false);
    const [calendarOperation, setCalendarOperation] = useState('apply');
    const [calendarScope, setCalendarScope] = useState('company');
    const [calendarUserIds, setCalendarUserIds] = useState([]);
    const [calendarStartDate, setCalendarStartDate] = useState(`${selectedMonth}-01`);
    const [calendarEndDate, setCalendarEndDate] = useState(`${selectedMonth}-01`);
    const [calendarStatus, setCalendarStatus] = useState('L');
    const [calendarNote, setCalendarNote] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [filterStatus, setFilterStatus] = useState('all');
    const [expandedEmployees, setExpandedEmployees] = useState({});
    const [lastRefreshTime, setLastRefreshTime] = useState(preview ? new Date() : null);

    // --- Day drill-in: punches, clock-time editing and force clock-out --------
    const [showDayDialog, setShowDayDialog] = useState(false);
    const [dayTarget, setDayTarget] = useState(null); // { user_id, user_name, date }
    const [dayLoading, setDayLoading] = useState(false);
    const [dayEntries, setDayEntries] = useState([]);
    const [dayTzLabel, setDayTzLabel] = useState('');
    const [dayIsClockedIn, setDayIsClockedIn] = useState(false);
    const [clockForm, setClockForm] = useState({ clock_in: '', clock_out: '', break_start: '', break_end: '', reason: '' });
    const [isSavingClock, setIsSavingClock] = useState(false);
    const [showForceClockOut, setShowForceClockOut] = useState(false);
    const [clockOutForm, setClockOutForm] = useState({ time: '', note: '' });
    const [isClockingOut, setIsClockingOut] = useState(false);

    const canManualMark = preview || canManuallyMarkAttendance || monthlyGridData.canManualMark;

    const statusLabelFor = (code) => {
        if (!code) return 'Auto';
        const match = monthlyGridData.statusOptions?.find((option) => option.code === code);
        return match?.label || code;
    };

    const fetchAttendanceData = async () => {
        setLoading(true);

        try {
            if (activeTab === 'monthly') {
                const response = await axios.get('/employee-attendance/monthly', { params: { month: selectedMonth } });
                setMonthlyGridData({
                    days: response.data.days || [],
                    employees: response.data.employees || [],
                    statusOptions: response.data.statusOptions || [],
                    canManualMark: Boolean(response.data.canManualMark),
                });
                setLastRefreshTime(new Date());
                return;
            }

            const endpoint = activeTab === 'summary'
                ? '/employee-attendance/summary'
                : activeTab === 'detailed'
                    ? '/employee-attendance/detailed'
                    : '/employee-attendance/timeline';
            const response = await axios.get(endpoint, { params: { date: selectedDate } });

            if (activeTab === 'summary') setEmployeesData(response.data.employees || []);
            if (activeTab === 'detailed') setDetailedActivityData(response.data.activities || []);
            if (activeTab === 'timeline') setTimelineData(response.data.timelines || []);
            setLastRefreshTime(new Date());
        } catch (error) {
            console.error('Attendance load failed:', error);
            showError('Unable to load attendance data.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (preview) return;
        fetchAttendanceData();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedDate, selectedMonth, activeTab]);

    const updateManualStatus = async (userId, date, statusCode) => {
        // Preview: mutate the in-memory grid so the manual-mark menu visibly works
        // without any network call.
        if (preview) {
            setMonthlyGridData((prev) => ({
                ...prev,
                employees: prev.employees.map((employee) => (
                    employee.user_id !== userId ? employee : {
                        ...employee,
                        days: employee.days.map((cell) => (
                            cell.date !== date ? cell : {
                                ...cell,
                                status_code: statusCode || null,
                                status_label: statusCode ? statusLabelFor(statusCode) : 'Auto',
                                source: statusCode ? 'manual' : 'automatic',
                            }
                        )),
                    }
                )),
            }));
            toast.success(statusCode ? 'Attendance status updated.' : 'Manual status cleared.');
            return;
        }

        const cellKey = `${userId}-${date}`;
        setMarkingCell(cellKey);

        try {
            await axios.patch('/employee-attendance/manual-status', {
                user_id: userId,
                date,
                status_code: statusCode || null,
            });
            toast.success(statusCode ? 'Attendance status updated.' : 'Manual status cleared.');
            await fetchAttendanceData();
            if (showHistoryDialog) await fetchManualHistory();
        } catch (error) {
            console.error('Manual attendance update failed:', error);
            showError('Unable to update attendance status.');
        } finally {
            setMarkingCell(null);
        }
    };

    // ---------------------------------------------------------------------
    // Day drill-in — open one employee's attendance day, list its punches,
    // correct the clock times, or clock out someone left running.
    // ---------------------------------------------------------------------
    const openDayDialog = async (employee, date) => {
        const target = { user_id: employee.user_id, user_name: employee.user_name, date };
        setDayTarget(target);
        setDayEntries([]);
        setDayTzLabel('');
        setDayIsClockedIn(false);
        setShowForceClockOut(false);
        setClockOutForm({ time: '', note: '' });
        setClockForm({ clock_in: '', clock_out: '', break_start: '', break_end: '', reason: '' });
        setShowDayDialog(true);

        if (preview) {
            const sample = DEV_SAMPLE.day;
            setDayEntries(sample.entries);
            setDayTzLabel(sample.timezone_label);
            setDayIsClockedIn(sample.is_clocked_in);
            setClockForm({
                clock_in: sample.clock_in,
                clock_out: sample.clock_out,
                break_start: sample.break_start,
                break_end: sample.break_end,
                reason: '',
            });
            return;
        }

        setDayLoading(true);
        try {
            const response = await axios.get('/employee-attendance/day-entries', {
                params: { user_id: employee.user_id, date },
            });
            setDayEntries(response.data.entries || []);
            setDayTzLabel(response.data.timezone_label || '');
            setDayIsClockedIn(Boolean(response.data.is_clocked_in));
            setClockForm({
                clock_in: response.data.clock_in || '',
                clock_out: response.data.clock_out || '',
                break_start: response.data.break_start || '',
                break_end: response.data.break_end || '',
                reason: '',
            });
        } catch (error) {
            console.error('Failed to load clock times:', error);
            showError('Unable to load this employee\'s clock times.');
        } finally {
            setDayLoading(false);
        }
    };

    const closeDayDialog = () => {
        setShowDayDialog(false);
        setDayTarget(null);
    };

    const firstErrorMessage = (error, fallback) => (
        error?.response?.data?.message
        || Object.values(error?.response?.data?.errors || {})[0]?.[0]
        || fallback
    );

    const saveClockTimes = async () => {
        if (!dayTarget) return;
        if (!clockForm.clock_in) {
            showError('Enter at least a clock-in time.');
            return;
        }
        if (!clockForm.reason.trim()) {
            showError('Please add a reason for this change.');
            return;
        }

        if (preview) {
            toast.success('Preview mode — clock times are not saved.');
            closeDayDialog();
            return;
        }

        setIsSavingClock(true);
        try {
            const response = await axios.post('/employee-attendance/clock-times', {
                user_id: dayTarget.user_id,
                date: dayTarget.date,
                clock_in: clockForm.clock_in || null,
                clock_out: clockForm.clock_out || null,
                break_start: clockForm.break_start || null,
                break_end: clockForm.break_end || null,
                reason: clockForm.reason,
            });
            toast.success(response.data?.message || 'Clock times updated.');
            closeDayDialog();
            await fetchAttendanceData();
        } catch (error) {
            showError(firstErrorMessage(error, 'Unable to update clock times.'));
        } finally {
            setIsSavingClock(false);
        }
    };

    const forceClockOut = async () => {
        if (!dayTarget) return;

        if (preview) {
            toast.success('Preview mode — nobody was actually clocked out.');
            setShowForceClockOut(false);
            return;
        }

        setIsClockingOut(true);
        try {
            const response = await axios.post('/employee-attendance/clock-out', {
                user_id: dayTarget.user_id,
                time: clockOutForm.time || null,
                note: clockOutForm.note || null,
            });
            toast.success(response.data?.message || 'Clocked out.');
            setShowForceClockOut(false);
            closeDayDialog();
            await fetchAttendanceData();
        } catch (error) {
            showError(firstErrorMessage(error, 'Could not clock this person out.'));
        } finally {
            setIsClockingOut(false);
        }
    };

    const openCalendarDialog = () => {
        const defaultDate = selectedMonth === selectedDate.slice(0, 7)
            ? selectedDate
            : `${selectedMonth}-01`;
        setCalendarOperation('apply');
        setCalendarScope('company');
        setCalendarUserIds(monthlyGridData.employees.map((employee) => String(employee.user_id)));
        setCalendarStartDate(defaultDate);
        setCalendarEndDate(defaultDate);
        setCalendarStatus('L');
        setCalendarNote('');
        setShowCalendarDialog(true);
    };

    const toggleCalendarUser = (userId) => {
        const value = String(userId);
        setCalendarUserIds((current) => (
            current.includes(value)
                ? current.filter((item) => item !== value)
                : [...current, value]
        ));
    };

    const calendarPreview = useMemo(() => {
        const start = Date.parse(`${calendarStartDate}T00:00:00Z`);
        const end = Date.parse(`${calendarEndDate}T00:00:00Z`);
        const days = Number.isFinite(start) && Number.isFinite(end) && end >= start
            ? Math.floor((end - start) / 86400000) + 1
            : 0;
        const users = calendarScope === 'company'
            ? monthlyGridData.employees.length
            : calendarUserIds.length;

        return {
            days,
            users,
            personDays: days * users,
        };
    }, [
        calendarEndDate,
        calendarScope,
        calendarStartDate,
        calendarUserIds.length,
        monthlyGridData.employees.length,
    ]);

    const updateAttendanceCalendar = async () => {
        if (preview) {
            toast.success('Preview mode — attendance calendar changes are not saved.');
            setShowCalendarDialog(false);
            return;
        }

        setIsUpdatingCalendar(true);

        try {
            const response = await axios.post('/employee-attendance/calendar', {
                operation: calendarOperation,
                scope: calendarScope,
                user_ids: calendarScope === 'selected' ? calendarUserIds : [],
                start_date: calendarStartDate,
                end_date: calendarEndDate,
                status_code: calendarOperation === 'apply' ? calendarStatus : null,
                note: calendarOperation === 'apply' ? calendarNote : null,
            });

            toast.success(response.data?.message || 'Attendance calendar updated.');
            setShowCalendarDialog(false);
            await fetchAttendanceData();
            if (showHistoryDialog) await fetchManualHistory();
        } catch (error) {
            console.error('Attendance calendar update failed:', error);
            const errors = error.response?.data?.errors || {};
            showError(
                errors.user_ids?.[0]
                || errors.start_date?.[0]
                || errors.end_date?.[0]
                || errors.status_code?.[0]
                || 'Unable to update the attendance calendar.'
            );
        } finally {
            setIsUpdatingCalendar(false);
        }
    };

    const fetchManualHistory = async () => {
        if (preview) return;
        setHistoryLoading(true);

        try {
            const response = await axios.get('/employee-attendance/manual-history', {
                params: { month: selectedMonth },
            });
            setManualHistory(response.data.history || []);
        } catch (error) {
            console.error('Manual attendance history load failed:', error);
            showError('Unable to load attendance history.');
        } finally {
            setHistoryLoading(false);
        }
    };

    const openHistoryDialog = () => {
        setShowHistoryDialog(true);
        if (!preview) fetchManualHistory();
    };

    useEffect(() => {
        if (showHistoryDialog && !preview) fetchManualHistory();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedMonth]);

    const openSlackDialog = () => {
        setSlackUserIds(monthlyGridData.employees.map((employee) => String(employee.user_id)));
        setShowSlackDialog(true);
    };

    const toggleSlackUser = (userId) => {
        const value = String(userId);
        setSlackUserIds((current) => (
            current.includes(value)
                ? current.filter((item) => item !== value)
                : [...current, value]
        ));
    };

    const toggleSlackField = (field) => {
        setSlackFields((current) => (
            current.includes(field)
                ? current.filter((item) => item !== field)
                : [...current, field]
        ));
    };

    const sendAttendanceToSlack = async () => {
        if (preview) {
            toast.success('Preview mode — no Slack report was actually sent.');
            setShowSlackDialog(false);
            return;
        }

        setIsSendingSlack(true);

        try {
            const response = await axios.post('/employee-attendance/slack', {
                month: selectedMonth,
                user_ids: slackUserIds,
                include_fields: slackFields,
            });

            toast.success(response.data?.message || 'Attendance report sent to Slack.');
            setShowSlackDialog(false);
        } catch (error) {
            console.error('Attendance Slack send failed:', error);
            const errors = error.response?.data?.errors || {};
            showError(
                errors.slack?.[0]
                || errors.user_ids?.[0]
                || errors.include_fields?.[0]
                || 'Unable to send attendance report to Slack.'
            );
        } finally {
            setIsSendingSlack(false);
        }
    };

    const downloadCSV = async () => {
        if (preview) {
            toast.success('Preview mode — export is disabled.');
            return;
        }

        const loadingToast = showLoading('Preparing attendance report...');

        try {
            const response = await axios.get('/employee-attendance/export', {
                params: { date: selectedDate },
                responseType: 'blob',
            });
            const url = window.URL.createObjectURL(new Blob([response.data], { type: 'text/csv' }));
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = `employee-attendance-${selectedDate}.csv`;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            window.URL.revokeObjectURL(url);
            toast.dismiss(loadingToast);
            showExportSuccess();
        } catch (error) {
            console.error('Attendance export failed:', error);
            toast.dismiss(loadingToast);
            showError('Failed to export attendance report.');
        }
    };

    const normalizedSearch = searchQuery.trim().toLowerCase();
    const matchesSearch = (item) => {
        if (!normalizedSearch) return true;
        return item.user_name?.toLowerCase().includes(normalizedSearch)
            || item.designation?.toLowerCase().includes(normalizedSearch);
    };

    const filteredEmployees = employeesData.filter((employee) =>
        matchesSearch(employee) && (filterStatus === 'all' || employee.current_status === filterStatus)
    );
    const filteredActivities = detailedActivityData.filter(matchesSearch);
    const filteredTimelines = timelineData.filter(matchesSearch);
    const filteredMonthlyEmployees = monthlyGridData.employees.filter(matchesSearch);

    // ── Empty workspace vs empty filter ──────────────────────────────────────
    //
    // Every tab used to say some version of "no records — nobody matches the
    // selected month and search", which reads as an accusation on a workspace
    // that has simply never clocked in. Three genuinely different situations
    // hide behind one empty list, and each gets its own answer:
    //
    //   1. the workspace has never recorded a punch  (`hasAnyAttendance`, one
    //      server-side EXISTS — the AJAX tabs only ever see one day/month, so
    //      they cannot work this out for themselves);
    //   2. it has punches, just not on the day/month in view;
    //   3. it has rows in view and the SEARCH/STATUS box hid them — the only
    //      case where the customer really did filter something out.
    //
    // Case 3 is decided purely on what is already in memory (raw rows present,
    // filtered rows absent), so it costs nothing.
    const workspaceHasAttendance = preview ? true : hasAnyAttendance;
    const searchActive = normalizedSearch !== '';
    const statusActive = filterStatus !== 'all';

    const clearViewFilters = () => {
        setSearchQuery('');
        setFilterStatus('all');
    };

    const filterNames = [
        searchActive ? `“${searchQuery.trim()}”` : null,
        statusActive ? `status “${filterStatus}”` : null,
    ].filter(Boolean).join(' and ');

    /**
     * One empty state for all four tabs.
     *
     * @param rawCount   rows the server returned before the in-page filters
     * @param filtersOn  whether a search/status filter is currently narrowing them
     * @param icon       tab icon
     * @param firstRun   {title, description} for a workspace with no attendance at all
     * @param quiet      {title, description} for a day/month that simply has none
     */
    const renderAttendanceEmpty = ({ rawCount, filtersOn, icon, firstRun, quiet }) => {
        if (rawCount > 0 && filtersOn) {
            return (
                <EmptyState
                    icon={icon}
                    title="Nothing matches your search"
                    description={`${rawCount} ${rawCount === 1 ? 'person is' : 'people are'} recorded here, but none match ${filterNames}.`}
                    action={(
                        <Button variant="secondary" size="sm" onClick={clearViewFilters}>
                            Clear search
                        </Button>
                    )}
                />
            );
        }

        if (!workspaceHasAttendance) {
            return (
                <EmptyState
                    icon={icon}
                    title={firstRun.title}
                    description={firstRun.description}
                    action={(
                        <Button
                            as={Link}
                            variant="secondary"
                            size="sm"
                            href={preview ? '#' : route('desktop-downloads.index')}
                        >
                            Get the desktop app
                        </Button>
                    )}
                />
            );
        }

        return <EmptyState icon={icon} title={quiet.title} description={quiet.description} />;
    };

    const summaryMetrics = useMemo(() => {
        if (activeTab === 'monthly') {
            const totals = monthlyGridData.employees.reduce((carry, employee) => {
                carry.present += employee.summary?.present || 0;
                carry.absent += employee.summary?.absent || 0;
                carry.leave += employee.summary?.leave || 0;
                carry.hours += Number(employee.summary?.total_work_hours || 0);
                return carry;
            }, { present: 0, absent: 0, leave: 0, hours: 0 });

            // Contract accents: attendance-present = people → 3 (blue),
            // hours → 1 (violet). Absent/Leave are TRUE STATUS concepts, so
            // their chips stay a neutral amber (4) — never a viz identity hue —
            // and the values stay text-body; the real status colors live in the
            // grid chips + legend below.
            return [
                { label: 'Present Days', value: totals.present, icon: PlayCircle, accent: 3 },
                { label: 'Absent Days', value: totals.absent, icon: Square, accent: 4 },
                { label: 'Leave Days', value: totals.leave, icon: Coffee, accent: 4 },
                { label: 'Month Hours', value: formatHours(totals.hours), icon: Timer, accent: 1 },
            ];
        }

        const working = employeesData.filter((employee) => employee.current_status === 'Working').length;
        const onBreak = employeesData.filter((employee) => employee.current_status === 'On Break').length;
        const clockedOut = employeesData.filter((employee) => employee.current_status === 'Clocked Out').length;
        const workHours = employeesData.reduce((sum, employee) => sum + Number(employee.total_work_hours || 0), 0);
        // Active = people working now → 3 (blue), breaks → 4 (amber),
        // hours → 1 (violet). Clocked Out is a state count — amber-neutral
        // chip, the per-person status Badge below carries the true tone.
        return [
            { label: 'Active', value: working, icon: PlayCircle, accent: 3 },
            { label: 'On Break', value: onBreak, icon: Coffee, accent: 4 },
            { label: 'Clocked Out', value: clockedOut, icon: Square, accent: 4 },
            { label: 'Team Hours', value: formatHours(workHours), icon: Timer, accent: 1 },
        ];
    }, [activeTab, employeesData, monthlyGridData.employees]);

    const toggleEmployee = (employeeId) => {
        setExpandedEmployees((current) => ({ ...current, [employeeId]: !current[employeeId] }));
    };

    const setAllExpanded = (items, expanded) => {
        if (!expanded) {
            setExpandedEmployees({});
            return;
        }
        setExpandedEmployees(Object.fromEntries(items.map((item) => [item.user_id, true])));
    };

    const isMonthly = activeTab === 'monthly';

    return (
        <AuthenticatedLayout user={user} header="Attendance">
            <Head title="Attendance" />

            <div className="mx-auto max-w-[1600px] space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                {/* Toolbar — one primary action, the rest secondary/ghost. */}
                <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0">
                        <p className="text-sm text-muted">Monitor current status, work time, breaks, and daily activity.</p>
                        {lastRefreshTime && (
                            <p className="mt-0.5 text-xs text-subtle">Updated {lastRefreshTime.toLocaleTimeString()}</p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <label className="relative">
                            <span className="sr-only">{isMonthly ? 'Month' : 'Date'}</span>
                            <CalendarDays className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
                            {isMonthly ? (
                                <Input
                                    type="month"
                                    value={selectedMonth}
                                    onChange={(event) => setSelectedMonth(event.target.value)}
                                    className="w-auto pl-9"
                                    style={{ colorScheme }}
                                />
                            ) : (
                                <Input
                                    type="date"
                                    value={selectedDate}
                                    onChange={(event) => setSelectedDate(event.target.value)}
                                    className="w-auto pl-9"
                                    style={{ colorScheme }}
                                />
                            )}
                        </label>

                        {canManualMark && isMonthly && (
                            <>
                                <Button variant="secondary" icon={<CalendarRange className="h-4 w-4" />} onClick={openCalendarDialog}>
                                    Calendar
                                </Button>
                                <Button variant="ghost" icon={<History className="h-4 w-4" />} onClick={openHistoryDialog}>
                                    Audit
                                </Button>
                            </>
                        )}

                        {canSendSlack && isMonthly && (
                            <Button variant="primary" icon={<Send className="h-4 w-4" />} onClick={openSlackDialog}>
                                Send to Slack
                            </Button>
                        )}

                        {canExport && !isMonthly && (
                            <Button variant="primary" icon={<Download className="h-4 w-4" />} onClick={downloadCSV}>
                                Export CSV
                            </Button>
                        )}
                    </div>
                </div>

                {/* Metric tiles */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {summaryMetrics.map((metric) => {
                        const Icon = metric.icon;
                        return (
                            <KpiCard
                                key={metric.label}
                                accent={metric.accent}
                                label={metric.label}
                                value={metric.value}
                                icon={<Icon className="h-[18px] w-[18px]" />}
                            />
                        );
                    })}
                </div>

                {/* Content */}
                <Card padded={false} className="overflow-hidden">
                    <div className="flex flex-col gap-3 border-b border-line p-4 lg:flex-row lg:items-center lg:justify-between">
                        <Tabs
                            tabs={TABS.map((tab) => {
                                const Icon = tab.icon;
                                return { key: tab.id, label: tab.label, icon: <Icon className="h-4 w-4" /> };
                            })}
                            value={activeTab}
                            onChange={setActiveTab}
                            className="flex-wrap"
                        />

                        <div className="flex w-full flex-col gap-2 sm:flex-row lg:w-auto">
                            <label className="relative min-w-0 flex-1 sm:min-w-64">
                                <span className="sr-only">Search</span>
                                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted" />
                                <Input
                                    type="search"
                                    value={searchQuery}
                                    onChange={(event) => setSearchQuery(event.target.value)}
                                    placeholder="Search employee or designation"
                                    className="pl-9"
                                />
                            </label>

                            {activeTab === 'summary' && (
                                <Select
                                    value={filterStatus}
                                    onChange={(event) => setFilterStatus(event.target.value)}
                                    className="w-auto"
                                    style={{ colorScheme }}
                                >
                                    {STATUS_OPTIONS.map((status) => (
                                        <option key={status} value={status}>
                                            {status === 'all' ? 'All statuses' : status}
                                        </option>
                                    ))}
                                </Select>
                            )}
                        </div>
                    </div>

                    {loading ? (
                        <ContentSkeleton monthly={isMonthly} />
                    ) : (
                        <>
                            {activeTab === 'monthly' && (
                                filteredMonthlyEmployees.length === 0
                                    ? renderAttendanceEmpty({
                                        rawCount: monthlyGridData.employees.length,
                                        filtersOn: searchActive,
                                        icon: <Users className="h-5 w-5" />,
                                        firstRun: {
                                            title: 'No attendance recorded yet',
                                            description: 'This grid gives you a month at a glance — one row per person, one column per day, present / absent / leave marked automatically from their clock-ins. It fills as your team starts using the tracker.',
                                        },
                                        quiet: {
                                            title: `Nobody has attendance in ${selectedMonth}`,
                                            description: 'Your team has attendance on record, just not in this month. Pick another month above.',
                                        },
                                    })
                                    : (
                                        <MonthlyAttendanceGrid
                                            days={monthlyGridData.days}
                                            employees={filteredMonthlyEmployees}
                                            statusOptions={monthlyGridData.statusOptions}
                                            canManualMark={canManualMark}
                                            markingCell={markingCell}
                                            onStatusChange={updateManualStatus}
                                            canEditTimes={canEditTimes}
                                            onEditTimes={openDayDialog}
                                        />
                                    )
                            )}

                            {activeTab === 'summary' && (
                                filteredEmployees.length === 0
                                    ? renderAttendanceEmpty({
                                        rawCount: employeesData.length,
                                        filtersOn: searchActive || statusActive,
                                        icon: <Users className="h-5 w-5" />,
                                        firstRun: {
                                            title: 'Nobody has clocked in yet',
                                            description: "This is today's roll call — who is working, who is on break, hours in the office versus hours actually tracked. The first clock-in is what switches it on.",
                                        },
                                        quiet: {
                                            title: `Nobody clocked in on ${selectedDate}`,
                                            description: 'Your team has attendance on record, just none for this day — a weekend, a holiday, or a date before they started. Choose another date above.',
                                        },
                                    })
                                    : (
                                        <Table>
                                            <THead>
                                                <Tr>
                                                    <Th>Employee</Th>
                                                    <Th>Status</Th>
                                                    <Th className="text-right">Work Hours</Th>
                                                    <Th className="text-right">Break</Th>
                                                    <Th>First In</Th>
                                                    <Th>Last Activity</Th>
                                                    <Th className="text-right">Actions</Th>
                                                </Tr>
                                            </THead>
                                            <TBody>
                                                {filteredEmployees.map((employee) => (
                                                    <Tr key={employee.user_id}>
                                                        <Td><EmployeeIdentity employee={employee} /></Td>
                                                        <Td>
                                                            <Badge tone={LIVE_STATUS_TONE[employee.current_status] || 'neutral'} dot>
                                                                {employee.current_status}
                                                            </Badge>
                                                        </Td>
                                                        <Td className="text-right font-semibold tabular-nums">{formatHours(employee.total_work_hours)}</Td>
                                                        <Td className="text-right text-muted tabular-nums">{formatHours(employee.total_break_hours)}</Td>
                                                        <Td className="text-muted">{employee.first_clock_in || '-'}</Td>
                                                        <Td className="text-muted">{employee.last_action_time || '-'}</Td>
                                                        <Td className="text-right">
                                                            <div className="flex items-center justify-end gap-3">
                                                                <span className="text-muted tabular-nums">{employee.total_entries}</span>
                                                                {canEditTimes && (
                                                                    <Button
                                                                        variant="secondary"
                                                                        size="sm"
                                                                        icon={<Pencil className="h-3.5 w-3.5" />}
                                                                        onClick={() => openDayDialog(employee, selectedDate)}
                                                                        title="Open this day — view punches, correct clock times, or clock out"
                                                                    >
                                                                        Times
                                                                    </Button>
                                                                )}
                                                            </div>
                                                        </Td>
                                                    </Tr>
                                                ))}
                                            </TBody>
                                        </Table>
                                    )
                            )}

                            {activeTab === 'detailed' && (
                                filteredActivities.length === 0
                                    ? renderAttendanceEmpty({
                                        rawCount: detailedActivityData.length,
                                        filtersOn: searchActive,
                                        icon: <List className="h-5 w-5" />,
                                        firstRun: {
                                            title: 'No punches recorded yet',
                                            description: 'This tab lists every clock-in, break and clock-out as it happened, in order — the detail behind the summary, and what you check when a day looks wrong.',
                                        },
                                        quiet: {
                                            title: `No punches on ${selectedDate}`,
                                            description: 'There are punches on other days, just none on this one. Choose another date above.',
                                        },
                                    })
                                    : (
                                        <AccordionList
                                            items={filteredActivities}
                                            expandedEmployees={expandedEmployees}
                                            toggleEmployee={toggleEmployee}
                                            setAllExpanded={setAllExpanded}
                                            renderContent={(activity) => (
                                                <div className="space-y-2">
                                                    {activity.entries.map((entry) => {
                                                        const meta = actionMeta(entry.action_type);
                                                        const Icon = meta.icon;
                                                        return (
                                                            <div key={entry.id} className="flex items-center gap-3 rounded-lg border border-line bg-surface p-3">
                                                                <span className={cx('flex h-9 w-9 items-center justify-center rounded-lg', meta.tone)}>
                                                                    <Icon className="h-4 w-4" />
                                                                </span>
                                                                <div className="flex-1 min-w-0">
                                                                    <div className="text-sm font-semibold text-body">{meta.label}</div>
                                                                    <div className="text-xs text-subtle">{entry.formatted_time}</div>
                                                                </div>
                                                                {entry.notes && <div className="text-sm text-muted">{entry.notes}</div>}
                                                            </div>
                                                        );
                                                    })}
                                                </div>
                                            )}
                                        />
                                    )
                            )}

                            {activeTab === 'timeline' && (
                                filteredTimelines.length === 0
                                    ? renderAttendanceEmpty({
                                        rawCount: timelineData.length,
                                        filtersOn: searchActive,
                                        icon: <Timer className="h-5 w-5" />,
                                        firstRun: {
                                            title: 'No work sessions yet',
                                            description: 'This tab replays a day as blocks of work and break, so you can see how someone actually spent it rather than just the total. It draws itself from the first clock-in onwards.',
                                        },
                                        quiet: {
                                            title: `No sessions on ${selectedDate}`,
                                            description: 'There are sessions on other days, just none on this one. Choose another date above.',
                                        },
                                    })
                                    : (
                                        <AccordionList
                                            items={filteredTimelines}
                                            expandedEmployees={expandedEmployees}
                                            toggleEmployee={toggleEmployee}
                                            setAllExpanded={setAllExpanded}
                                            renderContent={(timeline) => (
                                                <div className="space-y-2">
                                                    {timeline.sessions.map((session, index) => (
                                                        <div key={`${timeline.user_id}-${index}`} className="flex flex-col gap-2 rounded-lg border border-line bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
                                                            <div className="flex items-center gap-3">
                                                                <span className={cx(
                                                                    'flex h-9 w-9 items-center justify-center rounded-lg',
                                                                    session.type === 'work' ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'
                                                                )}>
                                                                    {session.type === 'work' ? <Timer className="h-4 w-4" /> : <Coffee className="h-4 w-4" />}
                                                                </span>
                                                                <div>
                                                                    <div className="text-sm font-semibold text-body">
                                                                        {session.type === 'work' ? 'Work Session' : 'Break'}
                                                                    </div>
                                                                    <div className="text-xs text-subtle">
                                                                        {session.start_time} to {session.end_time || 'Ongoing'}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div className="text-sm font-bold text-body tabular-nums">{session.duration}</div>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        />
                                    )
                            )}
                        </>
                    )}
                </Card>
            </div>

            {/* Attendance calendar modal */}
            <DialogShell
                show={showCalendarDialog}
                onClose={() => setShowCalendarDialog(false)}
                title="Attendance calendar"
                description="Schedule or remove attendance statuses for a date range."
                footer={(
                    <>
                        <Button variant="ghost" onClick={() => setShowCalendarDialog(false)}>Cancel</Button>
                        <Button
                            variant={calendarOperation === 'clear' ? 'danger' : 'primary'}
                            loading={isUpdatingCalendar}
                            disabled={
                                calendarPreview.personDays === 0
                                || (calendarScope === 'selected' && calendarUserIds.length === 0)
                            }
                            onClick={updateAttendanceCalendar}
                        >
                            {calendarOperation === 'clear' ? 'Remove marks' : 'Apply status'}
                        </Button>
                    </>
                )}
            >
                <div className="space-y-5">
                    <Field label="Action">
                        <Tabs
                            tabs={[
                                { key: 'apply', label: 'Apply or replace' },
                                { key: 'clear', label: 'Remove scheduled marks' },
                            ]}
                            value={calendarOperation}
                            onChange={setCalendarOperation}
                            className="flex w-full"
                        />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Start date" htmlFor="cal-start">
                            <Input
                                id="cal-start"
                                type="date"
                                value={calendarStartDate}
                                style={{ colorScheme }}
                                onChange={(event) => {
                                    setCalendarStartDate(event.target.value);
                                    if (calendarEndDate < event.target.value) setCalendarEndDate(event.target.value);
                                }}
                            />
                        </Field>
                        <Field label="End date" htmlFor="cal-end">
                            <Input
                                id="cal-end"
                                type="date"
                                min={calendarStartDate}
                                value={calendarEndDate}
                                style={{ colorScheme }}
                                onChange={(event) => setCalendarEndDate(event.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="People">
                        <Tabs
                            tabs={[
                                { key: 'company', label: 'Whole company' },
                                { key: 'selected', label: 'Selected people' },
                            ]}
                            value={calendarScope}
                            onChange={setCalendarScope}
                            className="flex w-full"
                        />
                    </Field>

                    {calendarScope === 'selected' && (
                        <div>
                            <div className="mb-2 flex items-center justify-between">
                                <span className="text-sm font-medium text-body">Select people</span>
                                <div className="flex gap-1">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setCalendarUserIds(monthlyGridData.employees.map((employee) => String(employee.user_id)))}
                                    >
                                        Select all
                                    </Button>
                                    <Button variant="ghost" size="sm" onClick={() => setCalendarUserIds([])}>Clear</Button>
                                </div>
                            </div>
                            <div className="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-line bg-surface-2/40 p-2 sm:grid-cols-2">
                                {monthlyGridData.employees.map((employee) => (
                                    <div key={employee.user_id} className="rounded-md px-2 py-1.5 hover:bg-surface-2">
                                        <Checkbox
                                            className="w-full"
                                            checked={calendarUserIds.includes(String(employee.user_id))}
                                            onChange={() => toggleCalendarUser(employee.user_id)}
                                            label={employee.user_name}
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {calendarOperation === 'apply' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Status" htmlFor="cal-status">
                                <Select
                                    id="cal-status"
                                    value={calendarStatus}
                                    style={{ colorScheme }}
                                    onChange={(event) => setCalendarStatus(event.target.value)}
                                >
                                    {calendarStatusOptions.map((status) => (
                                        <option key={status.value} value={status.value}>{status.label}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label="Note" htmlFor="cal-note">
                                <Input
                                    id="cal-note"
                                    value={calendarNote}
                                    maxLength={255}
                                    onChange={(event) => setCalendarNote(event.target.value)}
                                    placeholder="Optional reason"
                                />
                            </Field>
                        </div>
                    )}

                    <div className={cx(
                        'rounded-lg border px-4 py-3',
                        calendarOperation === 'clear'
                            ? 'border-danger/30 bg-danger-soft'
                            : 'border-accent/30 bg-accent/10'
                    )}>
                        <div className="text-sm font-semibold text-body">
                            Preview: {calendarPreview.users} people x {calendarPreview.days} {calendarPreview.days === 1 ? 'day' : 'days'} = {calendarPreview.personDays} person-days
                        </div>
                        <p className="mt-1 text-xs leading-5 text-muted">
                            {calendarOperation === 'clear'
                                ? 'This removes all manual attendance marks in the selected range. Automatic attendance will appear again.'
                                : 'Existing manual marks in the selected range will be replaced with this status.'}
                        </p>
                    </div>
                </div>
            </DialogShell>

            {/* Manual audit history modal */}
            <DialogShell
                show={showHistoryDialog}
                onClose={() => setShowHistoryDialog(false)}
                title="Manual attendance audit"
                description={`Latest manual changes for ${selectedMonth}.`}
                footer={<Button variant="ghost" onClick={() => setShowHistoryDialog(false)}>Close</Button>}
            >
                {historyLoading ? (
                    <div className="space-y-2">
                        {Array.from({ length: 5 }).map((_, index) => (
                            <Skeleton key={index} className="h-10 w-full" />
                        ))}
                    </div>
                ) : manualHistory.length === 0 ? (
                    <EmptyState
                        icon={<History className="h-5 w-5" />}
                        title="No manual changes"
                        description="No manual attendance edits were recorded for this month."
                    />
                ) : (
                    <div className="rounded-lg border border-line">
                        <Table>
                            <THead>
                                <Tr>
                                    <Th>Date</Th>
                                    <Th>Employee</Th>
                                    <Th>Change</Th>
                                    <Th>Changed by</Th>
                                    <Th>Changed at</Th>
                                    <Th>Reason</Th>
                                </Tr>
                            </THead>
                            <TBody>
                                {manualHistory.map((entry) => (
                                    <Tr key={entry.id}>
                                        <Td className="whitespace-nowrap font-semibold">{entry.attendance_date}</Td>
                                        <Td>
                                            <div className="font-semibold text-body">{entry.employee_name}</div>
                                            <div className="text-xs text-subtle">{entry.employee_designation || 'Member'}</div>
                                        </Td>
                                        <Td>
                                            <div className="flex items-center gap-2">
                                                <AuditStatusChip code={entry.old_status_code} label={entry.old_status_label} />
                                                <span className="text-subtle">to</span>
                                                <AuditStatusChip code={entry.new_status_code} label={entry.new_status_label} />
                                            </div>
                                        </Td>
                                        <Td className="whitespace-nowrap text-muted">{entry.changed_by}</Td>
                                        <Td className="whitespace-nowrap text-muted">{entry.changed_at || '-'}</Td>
                                        <Td className="min-w-48 text-muted">{entry.reason || '-'}</Td>
                                    </Tr>
                                ))}
                            </TBody>
                        </Table>
                    </div>
                )}
            </DialogShell>

            {/* Send to Slack modal */}
            <DialogShell
                show={showSlackDialog}
                onClose={() => setShowSlackDialog(false)}
                title="Send attendance to Slack"
                description={`Choose users and summary columns for ${selectedMonth}.`}
                footer={(
                    <>
                        <Button variant="ghost" onClick={() => setShowSlackDialog(false)}>Cancel</Button>
                        <Button
                            variant="primary"
                            loading={isSendingSlack}
                            disabled={!effectiveSlackConfigured || slackUserIds.length === 0 || slackFields.length === 0}
                            onClick={sendAttendanceToSlack}
                        >
                            Send report
                        </Button>
                    </>
                )}
            >
                <div className="space-y-5">
                    <div className={cx(
                        'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                        effectiveSlackConfigured ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'
                    )}>
                        <span aria-hidden="true" className="h-2 w-2 rounded-full bg-current" />
                        {effectiveSlackConfigured ? 'Slack webhook connected.' : 'Slack webhook is not configured.'}
                    </div>

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <h3 className="text-sm font-medium text-body">Users to include</h3>
                            <div className="flex gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setSlackUserIds(monthlyGridData.employees.map((employee) => String(employee.user_id)))}
                                >
                                    Select all
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setSlackUserIds([])}>Clear</Button>
                            </div>
                        </div>
                        <div className="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-line bg-surface-2/40 p-2 sm:grid-cols-2">
                            {monthlyGridData.employees.map((employee) => (
                                <div key={employee.user_id} className="rounded-md px-2 py-1.5 hover:bg-surface-2">
                                    <Checkbox
                                        className="w-full"
                                        checked={slackUserIds.includes(String(employee.user_id))}
                                        onChange={() => toggleSlackUser(employee.user_id)}
                                        label={employee.user_name}
                                    />
                                </div>
                            ))}
                        </div>
                    </div>

                    <fieldset>
                        <legend className="mb-2 text-sm font-medium text-body">Data to include</legend>
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            {attendanceSlackFieldOptions.map((field) => {
                                const checked = slackFields.includes(field.value);
                                return (
                                    <div
                                        key={field.value}
                                        className={cx(
                                            'rounded-lg border p-3 text-sm transition',
                                            checked
                                                ? 'border-accent bg-accent/10'
                                                : 'border-line bg-surface-2/40 hover:bg-surface-2'
                                        )}
                                    >
                                        <Checkbox
                                            className="w-full"
                                            checked={checked}
                                            onChange={() => toggleSlackField(field.value)}
                                            label={field.label}
                                        />
                                    </div>
                                );
                            })}
                        </div>
                    </fieldset>

                    <p className="rounded-lg bg-surface-2/60 px-3 py-2 text-xs leading-5 text-muted">
                        Late coming is calculated from each person&apos;s shift start time and grace period, and counts in both Present and Late coming totals. Late joining is for days before the employee&apos;s joining date.
                    </p>
                </div>
            </DialogShell>

            {/* Day drill-in: punches + clock-time editing + force clock-out */}
            <DialogShell
                show={showDayDialog}
                onClose={closeDayDialog}
                title="Clock times"
                description={dayTarget ? `${dayTarget.user_name} · ${dayTarget.date}` : ''}
                footer={(
                    <>
                        <Button variant="ghost" onClick={closeDayDialog}>Cancel</Button>
                        <Button
                            variant="primary"
                            loading={isSavingClock}
                            disabled={dayLoading || !clockForm.clock_in || !clockForm.reason.trim()}
                            onClick={saveClockTimes}
                        >
                            Save clock times
                        </Button>
                    </>
                )}
            >
                {dayLoading ? (
                    <div className="space-y-2">
                        {Array.from({ length: 4 }).map((_, index) => (
                            <Skeleton key={index} className="h-10 w-full" />
                        ))}
                    </div>
                ) : (
                    <div className="space-y-5">
                        {dayTzLabel && (
                            <p className="rounded-lg bg-info-soft px-3 py-2 text-xs font-medium text-info">
                                Times are shown and saved in {dayTzLabel} — this workspace&apos;s business timezone.
                            </p>
                        )}

                        {/* This day's punches, exactly as recorded. */}
                        <div>
                            <h3 className="mb-2 text-sm font-medium text-body">Punches on this day</h3>
                            {dayEntries.length === 0 ? (
                                <p className="rounded-lg border border-line bg-surface-2/40 px-3 py-3 text-sm text-muted">
                                    No clock punches were recorded on this day.
                                </p>
                            ) : (
                                <ul className="divide-y divide-line rounded-lg border border-line">
                                    {dayEntries.map((entry) => {
                                        const meta = actionMeta(entry.action_type);
                                        const Icon = meta.icon;
                                        return (
                                            <li key={entry.id} className="flex items-center gap-3 px-3 py-2.5">
                                                <span className={cx('flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', meta.tone)}>
                                                    <Icon className="h-4 w-4" />
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm font-semibold text-body">{meta.label}</div>
                                                    {entry.notes && <div className="truncate text-xs text-subtle">{entry.notes}</div>}
                                                </div>
                                                <span className="shrink-0 text-sm text-muted tabular-nums">{entry.time}</span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>

                        {/* Still on the clock — offer to close the session. */}
                        {dayIsClockedIn && (
                            <div className="rounded-lg border border-warning/30 bg-warning-soft px-4 py-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <div className="min-w-0">
                                        <div className="text-sm font-semibold text-warning">Still clocked in</div>
                                        <p className="mt-0.5 text-xs leading-5 text-muted">
                                            This person never clocked out. Closing the session writes a real clock-out
                                            (defaulting to their shift end, not &quot;now&quot;) so their hours stay accurate.
                                        </p>
                                    </div>
                                    {!showForceClockOut && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            icon={<LogOut className="h-4 w-4" />}
                                            onClick={() => setShowForceClockOut(true)}
                                        >
                                            Clock them out
                                        </Button>
                                    )}
                                </div>

                                {showForceClockOut && (
                                    <div className="mt-3 space-y-3 border-t border-warning/30 pt-3">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <Field label="Clock-out time" htmlFor="force-clockout-time" hint="Leave blank to use their shift end.">
                                                <Input
                                                    id="force-clockout-time"
                                                    type="time"
                                                    value={clockOutForm.time}
                                                    style={{ colorScheme }}
                                                    onChange={(event) => setClockOutForm((form) => ({ ...form, time: event.target.value }))}
                                                />
                                            </Field>
                                            <Field label="Note" htmlFor="force-clockout-note">
                                                <Input
                                                    id="force-clockout-note"
                                                    value={clockOutForm.note}
                                                    maxLength={255}
                                                    placeholder="Optional — why you closed it"
                                                    onChange={(event) => setClockOutForm((form) => ({ ...form, note: event.target.value }))}
                                                />
                                            </Field>
                                        </div>
                                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                            <Button variant="ghost" size="sm" onClick={() => setShowForceClockOut(false)}>Cancel</Button>
                                            <Button variant="danger" size="sm" loading={isClockingOut} onClick={forceClockOut}>
                                                Clock out now
                                            </Button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {/* Correct the day's clock times. */}
                        <div className="space-y-4">
                            <p className="rounded-lg border border-accent/30 bg-accent/10 px-3 py-2 text-xs leading-5 text-muted">
                                Set this employee&apos;s real clock-in and clock-out (and any break). Saving replaces the
                                whole day with one clean sequence, keeps it filed on the correct attendance day for their
                                shift, and records the change in the audit log. Leave a field blank to omit it.
                            </p>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Clock in" htmlFor="clock-in" required>
                                    <Input
                                        id="clock-in"
                                        type="time"
                                        value={clockForm.clock_in}
                                        style={{ colorScheme }}
                                        onChange={(event) => setClockForm((form) => ({ ...form, clock_in: event.target.value }))}
                                    />
                                </Field>
                                <Field label="Clock out" htmlFor="clock-out">
                                    <Input
                                        id="clock-out"
                                        type="time"
                                        value={clockForm.clock_out}
                                        style={{ colorScheme }}
                                        onChange={(event) => setClockForm((form) => ({ ...form, clock_out: event.target.value }))}
                                    />
                                </Field>
                                <Field label="Break start" htmlFor="break-start">
                                    <Input
                                        id="break-start"
                                        type="time"
                                        value={clockForm.break_start}
                                        style={{ colorScheme }}
                                        onChange={(event) => setClockForm((form) => ({ ...form, break_start: event.target.value }))}
                                    />
                                </Field>
                                <Field label="Break end" htmlFor="break-end">
                                    <Input
                                        id="break-end"
                                        type="time"
                                        value={clockForm.break_end}
                                        style={{ colorScheme }}
                                        onChange={(event) => setClockForm((form) => ({ ...form, break_end: event.target.value }))}
                                    />
                                </Field>
                            </div>

                            <Field label="Reason" htmlFor="clock-reason" required>
                                <Input
                                    id="clock-reason"
                                    value={clockForm.reason}
                                    maxLength={255}
                                    placeholder="e.g. Forgot to clock in; arrived 9:00, confirmed with manager"
                                    onChange={(event) => setClockForm((form) => ({ ...form, reason: event.target.value }))}
                                />
                            </Field>
                        </div>
                    </div>
                )}
            </DialogShell>
        </AuthenticatedLayout>
    );
}

// A themed dialog shell over the shared Modal — header (title/description/close),
// a scrollable body and an optional footer, all on semantic tokens. Reused by all
// three attendance dialogs so they share one look.
function DialogShell({ show, onClose, title, description, children, footer, maxWidth = '2xl' }) {
    return (
        <Modal show={show} onClose={onClose} maxWidth={maxWidth}>
            <div className="flex max-h-[85vh] flex-col">
                <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="text-base font-semibold text-body">{title}</h2>
                        {description && <p className="mt-0.5 text-sm text-muted">{description}</p>}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="-mr-1 shrink-0 rounded-md p-1.5 text-muted transition hover:bg-surface-2 hover:text-body focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-app"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>

                {footer && (
                    <div className="flex flex-col-reverse gap-2 border-t border-line px-5 py-4 sm:flex-row sm:justify-end">
                        {footer}
                    </div>
                )}
            </div>
        </Modal>
    );
}

function ContentSkeleton({ monthly }) {
    return (
        <div className="space-y-3 p-4">
            <Skeleton className="h-8 w-64" />
            {Array.from({ length: monthly ? 6 : 5 }).map((_, index) => (
                <Skeleton key={index} className={cx('w-full', monthly ? 'h-12' : 'h-14')} />
            ))}
        </div>
    );
}

function AuditStatusChip({ code, label }) {
    const toneClass = GRID_TONE[code] || GRID_TONE.empty;
    return (
        <span
            className={cx('inline-flex min-w-14 items-center justify-center rounded-md px-2 py-1 text-xs font-bold', toneClass)}
            title={label}
        >
            {code || 'Auto'}
        </span>
    );
}

function AccordionList({ items, expandedEmployees, toggleEmployee, setAllExpanded, renderContent }) {
    return (
        <div>
            <div className="flex justify-end gap-1 border-b border-line px-4 py-3">
                <Button variant="ghost" size="sm" onClick={() => setAllExpanded(items, true)}>Expand all</Button>
                <Button variant="ghost" size="sm" onClick={() => setAllExpanded(items, false)}>Collapse all</Button>
            </div>

            <div className="divide-y divide-line">
                {items.map((item) => {
                    const expanded = Boolean(expandedEmployees[item.user_id]);
                    return (
                        <div key={item.user_id}>
                            <button
                                type="button"
                                onClick={() => toggleEmployee(item.user_id)}
                                className="flex w-full items-center justify-between gap-4 px-4 py-4 text-left transition hover:bg-surface-2/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-accent"
                            >
                                <EmployeeIdentity employee={item} size="large" />
                                <div className="flex items-center gap-3">
                                    {item.entries && <span className="text-sm font-semibold text-muted">{item.entries.length} actions</span>}
                                    {item.sessions && (
                                        <span className="hidden text-sm text-muted sm:inline">
                                            {formatHours(item.total_work_hours)} work, {formatHours(item.total_break_hours)} break
                                        </span>
                                    )}
                                    {expanded ? <ChevronUp className="h-5 w-5 text-subtle" /> : <ChevronDown className="h-5 w-5 text-subtle" />}
                                </div>
                            </button>
                            {expanded && <div className="border-t border-line bg-surface-2/30 p-4">{renderContent(item)}</div>}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function MonthlyAttendanceGrid({
    days,
    employees,
    statusOptions,
    canManualMark,
    markingCell,
    onStatusChange,
    canEditTimes = false,
    onEditTimes,
}) {
    const [openCell, setOpenCell] = useState(null);
    const [menuPosition, setMenuPosition] = useState(null);
    const summaryColumns = [
        { key: 'holidays', label: 'Holidays' },
        { key: 'present', label: 'Present' },
        { key: 'absent', label: 'Absent' },
        { key: 'leave', label: 'Leave' },
        { key: 'half_day', label: 'Half day' },
        { key: 'work_from_home', label: 'WFH' },
        { key: 'late_coming', label: 'Late coming' },
        { key: 'late_joining', label: 'Late joining' },
        { key: 'total_work_hours', label: 'Hours', format: (value) => formatHours(value) },
    ];

    useEffect(() => {
        if (!openCell) return undefined;

        const closeMenu = (event) => {
            if (
                event?.target?.closest?.('[data-attendance-menu]')
                || event?.target?.closest?.('[data-attendance-trigger]')
            ) {
                return;
            }
            setOpenCell(null);
            setMenuPosition(null);
        };
        const closeOnEscape = (event) => {
            if (event.key === 'Escape') closeMenu();
        };

        document.addEventListener('pointerdown', closeMenu);
        document.addEventListener('scroll', closeMenu, true);
        window.addEventListener('resize', closeMenu);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeMenu);
            document.removeEventListener('scroll', closeMenu, true);
            window.removeEventListener('resize', closeMenu);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [openCell]);

    const toggleCellMenu = (event, cellKey) => {
        if (openCell === cellKey) {
            setOpenCell(null);
            setMenuPosition(null);
            return;
        }

        const rect = event.currentTarget.getBoundingClientRect();
        const menuWidth = 208;
        const estimatedMenuHeight = 380;
        const viewportPadding = 8;
        const left = Math.min(
            Math.max(rect.left, viewportPadding),
            window.innerWidth - menuWidth - viewportPadding
        );
        const openAbove = (
            window.innerHeight - rect.bottom < estimatedMenuHeight
            && rect.top > window.innerHeight - rect.bottom
        );

        setMenuPosition(openAbove
            ? { left, bottom: window.innerHeight - rect.top + 6 }
            : { left, top: rect.bottom + 6 });
        setOpenCell(cellKey);
    };

    return (
        <div className="p-4">
            {/* Legend */}
            <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted">
                <span className="font-medium uppercase tracking-wide text-subtle">Legend</span>
                {gridLegend.map(([code, label]) => (
                    <span key={code} className="inline-flex items-center gap-1.5">
                        <span className={cx('inline-flex h-6 min-w-8 items-center justify-center rounded-md px-1.5 text-xs font-bold', GRID_TONE[code])}>{code}</span>
                        {label}
                    </span>
                ))}
            </div>

            <div className="overflow-x-auto rounded-lg border border-line">
                <table className="min-w-max border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th className="sticky left-0 z-20 min-w-56 border-b border-r border-line bg-surface-2 px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-muted">
                                Employee
                            </th>
                            {days.map((day) => (
                                <th
                                    key={day.date}
                                    className={cx(
                                        'min-w-12 border-b border-r border-line px-2 py-2.5 text-center text-xs font-medium uppercase',
                                        day.is_weekend ? 'bg-surface-2 text-accent' : 'bg-surface-2 text-muted'
                                    )}
                                >
                                    <div className="tracking-wide">{day.weekday}</div>
                                    <div className="mt-1 text-sm font-semibold text-body">{day.day}</div>
                                </th>
                            ))}
                            {summaryColumns.map((column) => (
                                <th key={column.key} className="min-w-20 border-b border-r border-line bg-surface-2 px-3 py-3 text-center text-xs font-medium uppercase tracking-wide text-muted">
                                    {column.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {employees.map((employee) => (
                            <tr key={employee.user_id} className="group">
                                <td className="sticky left-0 z-10 border-b border-r border-line bg-surface px-4 py-2.5 group-hover:bg-surface-2/60">
                                    <EmployeeIdentity employee={employee} />
                                </td>
                                {employee.days.map((cell) => {
                                    const cellKey = `${employee.user_id}-${cell.date}`;
                                    const statusCode = cell.status_code || '';
                                    const toneClass = GRID_TONE[statusCode] || GRID_TONE.empty;
                                    const isManual = cell.source === 'manual';
                                    const isLoading = markingCell === cellKey;
                                    const open = openCell === cellKey;
                                    // The cell menu is offered to anyone who can act on the day —
                                    // marking status manually, editing clock times, or both.
                                    const cellInteractive = canManualMark || canEditTimes;
                                    const titleParts = [
                                        cell.status_label,
                                        cell.work_hours ? `${formatHours(cell.work_hours)} worked` : null,
                                        cell.first_clock_in ? `First in ${cell.first_clock_in}` : null,
                                        isManual ? 'Manual mark' : null,
                                    ].filter(Boolean);

                                    return (
                                        <td key={cell.date} className="relative border-b border-r border-line p-1.5 text-center">
                                            <div
                                                className={cx('relative mx-auto flex h-11 min-w-11 items-center justify-center rounded-md text-sm font-bold', toneClass)}
                                                title={titleParts.join(' | ')}
                                            >
                                                {cellInteractive ? (
                                                    <button
                                                        type="button"
                                                        disabled={isLoading}
                                                        onClick={(event) => toggleCellMenu(event, cellKey)}
                                                        className="h-full w-full rounded-md px-1 text-sm font-bold text-inherit outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-1 focus-visible:ring-offset-app disabled:cursor-wait disabled:opacity-60"
                                                        aria-label={`Attendance for ${employee.user_name} on ${cell.date}`}
                                                        aria-expanded={open}
                                                        aria-haspopup="menu"
                                                        data-attendance-trigger
                                                    >
                                                        {isLoading ? '...' : statusCode || '-'}
                                                    </button>
                                                ) : (
                                                    <span>{statusCode || '-'}</span>
                                                )}
                                                {isManual && <span className="absolute right-1 top-1 h-1.5 w-1.5 rounded-full bg-current opacity-70" />}
                                            </div>
                                            {cellInteractive && open && menuPosition && typeof document !== 'undefined' && createPortal(
                                                <div
                                                    className="fixed z-[100] max-h-[calc(100vh-1rem)] w-52 overflow-y-auto rounded-lg border border-line bg-surface p-2 text-left shadow-xl"
                                                    style={menuPosition}
                                                    role="menu"
                                                    data-attendance-menu
                                                >
                                                    <div className="mb-1 px-2 text-xs font-medium text-subtle">{cell.date}</div>

                                                    {canEditTimes && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    setOpenCell(null);
                                                                    setMenuPosition(null);
                                                                    onEditTimes?.(employee, cell.date);
                                                                }}
                                                                className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-sm font-semibold text-body transition hover:bg-accent/10 hover:text-accent"
                                                                role="menuitem"
                                                            >
                                                                <Clock className="h-4 w-4" />
                                                                <span>Clock times…</span>
                                                            </button>
                                                            {canManualMark && <div className="my-1 border-t border-line" />}
                                                        </>
                                                    )}

                                                    {canManualMark && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                onClick={() => {
                                                                    onStatusChange(employee.user_id, cell.date, '');
                                                                    setOpenCell(null);
                                                                    setMenuPosition(null);
                                                                }}
                                                                className="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm font-semibold text-body transition hover:bg-surface-2"
                                                                role="menuitem"
                                                            >
                                                                <span>Auto</span>
                                                                <span className="text-muted">{statusCode || '-'}</span>
                                                            </button>
                                                            <div className="my-1 border-t border-line" />
                                                            {statusOptions.map((option) => (
                                                                <button
                                                                    key={option.code}
                                                                    type="button"
                                                                    onClick={() => {
                                                                        onStatusChange(employee.user_id, cell.date, option.code);
                                                                        setOpenCell(null);
                                                                        setMenuPosition(null);
                                                                    }}
                                                                    className="flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm text-muted transition hover:bg-accent/10 hover:text-accent"
                                                                    role="menuitem"
                                                                >
                                                                    <span>{option.label}</span>
                                                                    <span className={cx('inline-flex min-w-8 items-center justify-center rounded px-1.5 py-0.5 text-xs font-bold', GRID_TONE[option.code] || GRID_TONE.empty)}>
                                                                        {option.code}
                                                                    </span>
                                                                </button>
                                                            ))}
                                                        </>
                                                    )}
                                                </div>,
                                                document.body
                                            )}
                                        </td>
                                    );
                                })}
                                {summaryColumns.map((column) => (
                                    <td key={column.key} className="border-b border-r border-line bg-surface px-3 py-2.5 text-center font-semibold text-body tabular-nums group-hover:bg-surface-2/60">
                                        {column.format
                                            ? column.format(employee.summary?.[column.key] || 0)
                                            : employee.summary?.[column.key] || 0}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
