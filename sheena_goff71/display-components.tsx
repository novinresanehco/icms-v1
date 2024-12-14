const DisplaySystem = ({ content, layout = 'default' }) => {
  const [processed] = useState(() => sanitizeContent(content));

  return (
    <div className="template-display">
      <div className={`layout-${layout} w-full mx-auto p-4`}>
        <div className="content-wrapper" 
             dangerouslySetInnerHTML={{__html: processed}} />
      </div>
    </div>
  );
};

const Gallery = ({ items }) => (
  <div className="grid grid-cols-3 gap-4">
    {items.map(item => (
      <div key={item.id} className="aspect-square overflow-hidden rounded-lg">
        <img src={item.url} 
             alt={item.title}
             className="w-full h-full object-cover"
             loading="lazy" />
      </div>
    ))}
  </div>
);

export default { DisplaySystem, Gallery };
