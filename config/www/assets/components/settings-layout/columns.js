(() => {
  const layout = document.querySelector('[data-settings-columns]');
  if (!layout) return;

  const columns = Array.from(layout.querySelectorAll('[data-settings-column]'));
  if (columns.length !== 2) return;

  const sections = columns.flatMap((column) => Array.from(column.children));
  const wideScreen = window.matchMedia('(min-width: 1280px)');
  let currentPlacement = '';
  let scheduled = false;

  const arrange = () => {
    scheduled = false;

    if (!wideScreen.matches) {
      if (currentPlacement !== 'single') {
        sections.forEach((section) => columns[0].append(section));
        columns[1].hidden = true;
        currentPlacement = 'single';
      }
      return;
    }

    columns[1].hidden = false;
    const heights = sections.map((section) => section.getBoundingClientRect().height);
    const gap = parseFloat(getComputedStyle(columns[0]).rowGap) || 0;
    let bestMask = 0;
    let bestDifference = Infinity;
    let bestCountDifference = Infinity;

    for (let mask = 1; mask < 1 << (sections.length - 1); mask += 1) {
      const totals = [heights[0], 0];
      const counts = [1, 0];

      for (let index = 1; index < sections.length; index += 1) {
        const columnIndex = (mask >> (index - 1)) & 1;
        totals[columnIndex] += heights[index] + (counts[columnIndex] > 0 ? gap : 0);
        counts[columnIndex] += 1;
      }

      const difference = Math.abs(totals[0] - totals[1]);
      const countDifference = Math.abs(counts[0] - counts[1]);
      if (difference < bestDifference || (difference === bestDifference && countDifference < bestCountDifference)) {
        bestMask = mask;
        bestDifference = difference;
        bestCountDifference = countDifference;
      }
    }

    const placement = String(bestMask);
    if (placement === currentPlacement) return;

    sections.forEach((section, index) => {
      const columnIndex = index === 0 ? 0 : (bestMask >> (index - 1)) & 1;
      columns[columnIndex].append(section);
    });
    currentPlacement = placement;
  };

  const schedule = () => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(arrange);
  };

  schedule();
  window.addEventListener('resize', schedule);
  wideScreen.addEventListener('change', schedule);
  if (document.fonts) document.fonts.ready.then(schedule);
  if (window.ResizeObserver) {
    const observer = new ResizeObserver(schedule);
    sections.forEach((section) => observer.observe(section));
  }
})();
