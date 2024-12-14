const TemplateRenderer = ({ template, data }) => {
  return (
    <div className="template-container">
      <div className="template-content p-4">
        <div dangerouslySetInnerHTML={{ __html: template }} />
      </div>
      {data.media && <MediaDisplay items={data.media} />}
    </div>
  );
};

const MediaDisplay = ({ items }) => {
  return (
    <div className="grid grid-cols-3 gap-4 p-4">
      {items.map(item => (
        <div key={item.id} className="aspect-square overflow-hidden rounded-lg">
          <img 
            src={item.url}
            alt={item.title}
            className="w-full h-full object-cover"
            loading="lazy"
          />
        </div>
      ))}
    </div>
  );
};

export default { TemplateRenderer, MediaDisplay };
