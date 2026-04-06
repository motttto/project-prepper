/**
 * CalDAV XML Response Builder
 * Template-literal basiert — kein XML-Library nötig
 */

const XML_HEADER = '<?xml version="1.0" encoding="utf-8"?>';
const NS = 'xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ical="http://apple.com/ns/ical/"';

export function multistatus(innerXml: string): string {
  return `${XML_HEADER}\n<d:multistatus ${NS}>\n${innerXml}</d:multistatus>`;
}

export function response(href: string, propstatXml: string): string {
  return `<d:response>\n<d:href>${escapeXml(href)}</d:href>\n${propstatXml}</d:response>\n`;
}

export function propstatOk(propsXml: string): string {
  return `<d:propstat>\n<d:prop>\n${propsXml}</d:prop>\n<d:status>HTTP/1.1 200 OK</d:status>\n</d:propstat>\n`;
}

export function propstatNotFound(propsXml: string): string {
  return `<d:propstat>\n<d:prop>\n${propsXml}</d:prop>\n<d:status>HTTP/1.1 404 Not Found</d:status>\n</d:propstat>\n`;
}

// === Property builders ===

export function principalProps(baseHref: string, displayName: string): string {
  return propstatOk([
    `<d:current-user-principal><d:href>${escapeXml(baseHref)}</d:href></d:current-user-principal>`,
    `<cal:calendar-home-set><d:href>${escapeXml(baseHref)}calendars/</d:href></cal:calendar-home-set>`,
    `<d:displayname>${escapeXml(displayName)}</d:displayname>`,
    `<d:resourcetype><d:collection/><d:principal/></d:resourcetype>`,
  ].join("\n"));
}

export function calendarHomeProps(): string {
  return propstatOk([
    `<d:resourcetype><d:collection/></d:resourcetype>`,
  ].join("\n"));
}

export function calendarProps(name: string, color: string, ctag: number): string {
  return propstatOk([
    `<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>`,
    `<d:displayname>${escapeXml(name)}</d:displayname>`,
    `<ical:calendar-color>${escapeXml(color)}FF</ical:calendar-color>`,
    `<cs:getctag>${ctag}</cs:getctag>`,
    `<cal:supported-calendar-component-set><cal:comp name="VEVENT"/></cal:supported-calendar-component-set>`,
    `<d:supported-report-set>`,
    `<d:supported-report><d:report><cal:calendar-multiget/></d:report></d:supported-report>`,
    `<d:supported-report><d:report><cal:calendar-query/></d:report></d:supported-report>`,
    `</d:supported-report-set>`,
    `<d:current-user-privilege-set>`,
    `<d:privilege><d:read/></d:privilege>`,
    `<d:privilege><d:write/></d:privilege>`,
    `<d:privilege><d:write-content/></d:privilege>`,
    `<d:privilege><d:bind/></d:privilege>`,
    `<d:privilege><d:unbind/></d:privilege>`,
    `</d:current-user-privilege-set>`,
  ].join("\n"));
}

export function eventProps(etag: string): string {
  return propstatOk([
    `<d:getetag>"${escapeXml(etag)}"</d:getetag>`,
    `<d:getcontenttype>text/calendar; charset=utf-8; component=VEVENT</d:getcontenttype>`,
    `<d:resourcetype/>`,
  ].join("\n"));
}

export function eventWithData(etag: string, calendarData: string): string {
  return propstatOk([
    `<d:getetag>"${escapeXml(etag)}"</d:getetag>`,
    `<cal:calendar-data>${escapeXml(calendarData)}</cal:calendar-data>`,
  ].join("\n"));
}

// === Request parsing ===

export function parseRequestedProps(xml: string): string[] {
  const props: string[] = [];
  // Match <d:prop><d:displayname/><d:resourcetype/>...</d:prop>
  const propMatch = xml.match(/<(?:\w+:)?prop[^>]*>([\s\S]*?)<\/(?:\w+:)?prop>/i);
  if (!propMatch) return ["allprop"];

  const propBlock = propMatch[1];
  // Extract tag names like <d:displayname/>, <cal:calendar-data/> etc
  const tagRegex = /<(?:\w+:)?(\w[\w-]*)\s*\/?>?/gi;
  let m;
  while ((m = tagRegex.exec(propBlock)) !== null) {
    props.push(m[1].toLowerCase());
  }
  return props.length > 0 ? props : ["allprop"];
}

export function parseTimeRange(xml: string): { start?: string; end?: string } | null {
  const trMatch = xml.match(/time-range\s+start="([^"]*)"(?:\s+end="([^"]*)")?/i);
  if (!trMatch) return null;
  return { start: trMatch[1], end: trMatch[2] };
}

export function parseMultigetHrefs(xml: string): string[] {
  const hrefs: string[] = [];
  const hrefRegex = /<(?:\w+:)?href>([^<]+)<\/(?:\w+:)?href>/gi;
  let m;
  while ((m = hrefRegex.exec(xml)) !== null) {
    hrefs.push(m[1]);
  }
  return hrefs;
}

export function isCalendarMultiget(xml: string): boolean {
  return xml.includes("calendar-multiget");
}

export function isCalendarQuery(xml: string): boolean {
  return xml.includes("calendar-query");
}

export function isSyncCollection(xml: string): boolean {
  return xml.includes("sync-collection");
}

// === Helper ===

function escapeXml(text: string): string {
  return text
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
