export function timeFormat(time) {
    const hours = Math.floor(time);
    const minutes = Math.round((time - hours) * 60);

    const hhmm = `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}`;

    return hhmm;
}
