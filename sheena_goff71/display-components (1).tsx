import React from 'react';

// Content Block Component
const ContentBlock = ({ content = '', className = '' }) => {
  if (!content) return null;

  return (
    <div className={`prose max-w-none ${className}`}>
      {typeof content === 'string' ? (
        <div dangerouslySetInnerHTML={{ __html: content }} />
      ) : content}
    </div>
  );
};

// Template Zone Component
const TemplateZone = ({ content, type = 'content', className = '' }) => {
  const zoneClasses = {
    header: 'bg-white border-b',
    main: 'py-8',
    sidebar: 'py-8 px-4 bg-gray-50',
    footer: 'bg-white border-t'
  };

  return (
    <div className={`template-zone ${zoneClasses[type]} ${className}`}>
      <ContentBlock content={content} />
    </div>
  );
};

// Template Display Manager
export default function DisplayManager({ 
  zones = {},
  className = ''
}) {
  return (
    <div className={`template-display ${className}`}>
      {Object.entries(zones).map(([type, content]) => (
        <TemplateZone
          key={type}
          type={type}
          content={content}
          className={`zone-${type}`}
        />
      ))}
    </div>
  );
}
