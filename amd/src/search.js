define([], function() {
  'use strict';

  const $$ = (root, sel) => Array.from(root.querySelectorAll(sel));
  const $  = (root, sel) => root.querySelector(sel);
  const norm = (t) => (t || '').toString().trim().toLowerCase();

  function buildCompIndex(compEl) {
    const parts = [];
    parts.push(compEl.dataset.compName, compEl.dataset.compFramework, compEl.dataset.compId);

    const title = $(compEl, '.cru-comp-title')?.textContent;
    const fw    = $(compEl, '.cru-comp-framework')?.textContent;
    const desc  = $(compEl, '.cru-comp-desc')?.textContent;
    parts.push(title, fw, desc);

    $$(compEl, '.cru-course').forEach(courseEl => {
      parts.push(courseEl.dataset.courseName);
      const linkText = $(courseEl, '.cru-course-link')?.textContent;
      const shortTxt = $(courseEl, '.cru-course-short')?.textContent;
      parts.push(linkText, shortTxt);

      $$(courseEl, '.cru-activity').forEach(actEl => {
        parts.push(actEl.dataset.activityName);
      });
    });

    return norm(parts.filter(Boolean).join(' '));
  }

  function bindTemplate(root, cfg) {
    const toolbarRoot = cfg?.toolbar ? document.querySelector(cfg.toolbar) : root;

    // ✅ look for inputs in the toolbar (or root if no toolbar provided)
    const searchInput = $(toolbarRoot, '.lp-search');
    const clearBtn    = $(toolbarRoot, '.lp-clear');
    const resCountEl  = $(toolbarRoot, '.lp-result-count');

    const emptyEl     = $(root, '.lp-empty');
    const groupEls    = $$(root, '.cru-group');
    const compEls     = $$(root, '.cru-comp');

    if (!searchInput) return;

    const compIndex = new Map();
    compEls.forEach(c => compIndex.set(c, buildCompIndex(c)));

    function applyFilter() {
      const q = norm(searchInput.value);
      let visibleGroups = 0;
      let visibleComps  = 0;
      let visibleCourses = 0;
      let visibleActivities = 0;

      compEls.forEach(compEl => {
        const blob = compIndex.get(compEl);
        const compMatches = !q || blob.includes(q);

        let anyCourseVisible = false;
        $$(compEl, '.cru-course').forEach(courseEl => {
          let courseVisible = !q;
          if (q) {
            const courseBlob = norm([
              courseEl.dataset.courseName,
              $(courseEl, '.cru-course-link')?.textContent,
              $(courseEl, '.cru-course-short')?.textContent
            ].filter(Boolean).join(' '));

            let anyActVisible = false;
            $$(courseEl, '.cru-activity').forEach(actEl => {
              const actName = norm(actEl.dataset.activityName);
              const actVisible = actName.includes(q) || courseBlob.includes(q) || compMatches;
              actEl.style.display = actVisible ? '' : 'none';
              if (actVisible) { anyActVisible = true; visibleActivities++; }
            });

            courseVisible = compMatches || courseBlob.includes(q) || anyActVisible;
          }

          courseEl.style.display = courseVisible ? '' : 'none';
          if (courseVisible) { anyCourseVisible = true; visibleCourses++; }
        });

        const compVisible = !q || compMatches || anyCourseVisible;
        compEl.style.display = compVisible ? '' : 'none';
        if (compVisible) { visibleComps++; }

        if (q) compEl.open = compVisible;
      });

      groupEls.forEach(groupEl => {
        const innerComps = $$(groupEl, '.cru-comp');
        const groupHasVisible = innerComps.some(c => c.style.display !== 'none');
        groupEl.style.display = groupHasVisible ? '' : 'none';
        if (q) groupEl.open = groupHasVisible;
        if (groupHasVisible) visibleGroups++;
      });

      const hasAnyVisible = visibleComps > 0 && visibleGroups > 0;
      if (emptyEl) emptyEl.style.display = q && !hasAnyVisible ? '' : 'none';

      if (resCountEl) {
        if (q) {
          resCountEl.style.display = 'block';
          resCountEl.textContent =
            `${visibleComps} competenc${visibleComps === 1 ? 'y' : 'ies'}, ` +
            `${visibleCourses} course${visibleCourses === 1 ? '' : 's'}, ` +
            `${visibleActivities} activit${visibleActivities === 1 ? 'y' : 'ies'} for “${q}”`;
        } else {
          resCountEl.style.display = 'none';
          resCountEl.textContent = '';
        }
      }

      if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';
    }

    searchInput.addEventListener('input', applyFilter);

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        applyFilter();
        searchInput.focus();
      });
    }

    // Slash-focus
    root.addEventListener('keydown', (evt) => {
      if (evt.key === '/' && document.activeElement !== searchInput) {
        evt.preventDefault();
        searchInput.focus();
      }
    });

    applyFilter();
  }

  function init(config) {
    const selector = (typeof config === 'string') ? config : (config?.root || '.lp-template');
    const toolbar  = (typeof config === 'object') ? config.toolbar : null;
    document.querySelectorAll(selector).forEach(root => bindTemplate(root, { toolbar }));
  }

  return { init };
});
